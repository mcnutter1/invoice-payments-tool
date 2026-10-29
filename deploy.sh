#!/usr/bin/env bash
# =============================================================================
# McNutt Finance — single deploy script (Ubuntu/Debian + Apache + PHP-FPM + MySQL/MariaDB)
#
#   sudo ./deploy.sh                 # full deploy (idempotent; safe to re-run)
#   sudo SKIP_LETSENCRYPT=1 ./deploy.sh
#   sudo BUILD_ASSETS=1 ./deploy.sh  # rebuild CSS with the Tailwind standalone CLI
#
# Shared-server safety:
#   * Only writes files it owns: our vhost, our PHP-FPM pool, our cron/logrotate files,
#     /etc/mcnutt-finance, /var/lib/mcnutt-finance, /var/log/mcnutt-finance, /opt/mcnutt-finance.
#   * Never edits other vhosts/pools/databases. DB statements are scoped to one database + user.
#   * Validates with `apache2ctl configtest` / `php-fpmX.Y -t` before every *reload* (never restart)
#     and rolls our files back if validation fails.
#   * Never changes the system default PHP version.
# =============================================================================
set -euo pipefail

# --- Config (override with environment variables) ---------------------------
: "${REPO_URL:=https://github.com/mcnutter1/invoice-payments-tool.git}"
: "${BRANCH:=main}"
: "${DOMAIN:=invoices.mcnutt.cloud}"
: "${LE_EMAIL:=robert.mcnutt@gmail.com}"
: "${APP_DIR:=/var/www/${DOMAIN}}"
: "${RUN_AS:=www-data}"
: "${ASKPASS:=/usr/local/bin/git-askpass-github.sh}"
: "${TOKEN_FILE:=/etc/secrets/github_pat}"
: "${APP_SLUG:=mcnutt-finance}"
: "${ETC_DIR:=/etc/${APP_SLUG}}"
: "${ENV_FILE:=${ETC_DIR}/finance.env}"
: "${DATA_DIR:=/var/lib/${APP_SLUG}}"
: "${LOG_DIR:=/var/log/${APP_SLUG}}"
: "${OPT_DIR:=/opt/${APP_SLUG}}"
: "${BACKUP_DIR:=/var/backups/${APP_SLUG}}"
: "${BACKUP_RETENTION_DAYS:=30}"
: "${DB_NAME:=mcnutt_finance}"
: "${DB_USER:=mcnutt_finance}"
: "${MYSQL_ADMIN:=mysql}"            # e.g. "mysql --defaults-file=/root/.my.cnf" if root needs a password
: "${PHP_VERSION:=}"                 # auto-detect highest installed >= 8.2 when empty
: "${ALLOW_PHP_INSTALL:=0}"          # 1 = install php8.3 side-by-side if no >= 8.2 exists (default PHP is preserved)
: "${SKIP_LETSENCRYPT:=0}"
: "${BUILD_ASSETS:=0}"
: "${TAILWIND_VERSION:=v3.4.17}"

FPM_POOL_NAME="${APP_SLUG}"
APP_TAG="${APP_SLUG//-/_}"
FPM_SOCK="/run/php/${APP_SLUG}.sock"
VHOST_FILE="/etc/apache2/sites-available/${DOMAIN}.conf"
ACME_ROOT="${DATA_DIR}/acme"
LE_SCRIPT="/usr/local/sbin/${APP_SLUG}-letsencrypt.sh"
BACKUP_SCRIPT="/usr/local/sbin/${APP_SLUG}-backup.sh"
RESTORE_CHECK_SCRIPT="/usr/local/sbin/${APP_SLUG}-restore-check.sh"
CRON_FILE="/etc/cron.d/${APP_SLUG}"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
PRESERVE_LOCAL=()

export GIT_ASKPASS="$ASKPASS"
export GIT_TERMINAL_PROMPT=0

log()  { echo "[INFO] $*"; }
warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

require_root() { [ "$(id -u)" -eq 0 ] || die "This script must be run with sudo/root."; }

as_user() { sudo -u "$RUN_AS" --preserve-env=GIT_ASKPASS,GIT_TERMINAL_PROMPT,COMPOSER_HOME bash -lc "$*"; }

# --- 1. Platform & PHP ------------------------------------------------------
detect_php() {
  if [ -n "$PHP_VERSION" ]; then
    [ -x "/usr/bin/php${PHP_VERSION}" ] || die "PHP_VERSION=${PHP_VERSION} requested but /usr/bin/php${PHP_VERSION} is missing."
    return
  fi
  for v in 8.4 8.3 8.2; do
    if [ -x "/usr/bin/php${v}" ]; then PHP_VERSION="$v"; break; fi
  done
  if [ -z "$PHP_VERSION" ]; then
    if [ "$ALLOW_PHP_INSTALL" = "1" ]; then
      install_php_side_by_side
    else
      die "No PHP >= 8.2 found. Re-run with ALLOW_PHP_INSTALL=1 to install PHP 8.3 side-by-side (system default 'php' is left unchanged)."
    fi
  fi
  log "Using PHP ${PHP_VERSION} (/usr/bin/php${PHP_VERSION})"
}

install_php_side_by_side() {
  local prev_alt=""
  prev_alt="$(update-alternatives --query php 2>/dev/null | awk '/^Value:/{print $2}')" || true
  if ! apt-cache policy php8.3-fpm | grep -q 'Candidate: [0-9]'; then
    log "Adding ondrej/php PPA for PHP 8.3"
    apt-get install -y software-properties-common >/dev/null
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
  fi
  apt-get install -y --no-install-recommends php8.3-cli php8.3-fpm >/dev/null
  PHP_VERSION=8.3
  if [ -n "$prev_alt" ] && [ -x "$prev_alt" ]; then
    update-alternatives --set php "$prev_alt" >/dev/null 2>&1 || true
    log "Restored system default php -> ${prev_alt}"
  fi
}

ensure_packages() {
  local need=() ext
  command -v apache2ctl >/dev/null || die "Apache (apache2) is not installed. This script deploys into an existing Apache server."
  for p in git curl unzip openssl cron; do command -v "$p" >/dev/null || need+=("$p"); done
  command -v mysql >/dev/null || need+=("default-mysql-client")
  command -v certbot >/dev/null || need+=("certbot")
  [ -x "/usr/sbin/php-fpm${PHP_VERSION}" ] || need+=("php${PHP_VERSION}-fpm")
  local mods; mods="$(/usr/bin/php${PHP_VERSION} -m | tr '[:upper:]' '[:lower:]')"
  for ext in mysql:pdo_mysql mbstring:mbstring xml:dom curl:curl zip:zip gd:gd intl:intl bcmath:bcmath; do
    local pkg="${ext%%:*}" mod="${ext##*:}"
    echo "$mods" | grep -qx "$mod" || need+=("php${PHP_VERSION}-${pkg}")
  done
  if [ "${#need[@]}" -gt 0 ]; then
    log "Installing missing packages (additive only): ${need[*]}"
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${need[@]}" >/dev/null
    systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null || systemctl start "php${PHP_VERSION}-fpm"
  fi
  systemctl is-active --quiet "php${PHP_VERSION}-fpm" || systemctl start "php${PHP_VERSION}-fpm"
}

# --- 2. Directories ---------------------------------------------------------
ensure_dirs() {
  mkdir -p "$APP_DIR" "$ETC_DIR" "$DATA_DIR/private" "$DATA_DIR/composer" "$ACME_ROOT/.well-known/acme-challenge" "$LOG_DIR" "$OPT_DIR/bin" "$BACKUP_DIR"
  chown "$RUN_AS:$RUN_AS" "$APP_DIR"
  chown root:"$RUN_AS" "$ETC_DIR"; chmod 750 "$ETC_DIR"
  chown -R "$RUN_AS:$RUN_AS" "$DATA_DIR/private" "$DATA_DIR/composer"
  chmod 770 "$DATA_DIR/private"
  chown root:"$RUN_AS" "$DATA_DIR"; chmod 750 "$DATA_DIR"
  chmod 755 "$ACME_ROOT" "$ACME_ROOT/.well-known" "$ACME_ROOT/.well-known/acme-challenge"
  chown "$RUN_AS:$RUN_AS" "$LOG_DIR"; chmod 750 "$LOG_DIR"
  chmod 700 "$BACKUP_DIR"
}

# --- 3. Git (mirrors the existing deploy convention) ------------------------
ensure_askpass_and_token_perms() {
  if [ ! -f "$ASKPASS" ]; then
    echo "[ERROR] ASKPASS helper not found at $ASKPASS. Create it with:"
    cat <<'EOS'
sudo tee /usr/local/bin/git-askpass-github.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
case "$1" in
  *'Username for '*github.com*) echo "x-access-token" ;;
  *'Password for '*github.com*) cat /etc/secrets/github_pat ;;
  *) echo "" ;;
esac
EOF
sudo chmod 750 /usr/local/bin/git-askpass-github.sh
sudo chgrp www-data /usr/local/bin/git-askpass-github.sh
EOS
    exit 1
  fi
  chgrp "$RUN_AS" "$ASKPASS" || true
  chmod 750 "$ASKPASS"
  [ -f "$TOKEN_FILE" ] || die "Token file not found at $TOKEN_FILE (one line containing a GitHub PAT with read access)."
  chgrp -R "$RUN_AS" "$(dirname "$TOKEN_FILE")" || true
  chmod 710 "$(dirname "$TOKEN_FILE")"
  chmod 640 "$TOKEN_FILE"
}

auth_smoke_test() {
  log "Testing GitHub auth via ASKPASS ..."
  as_user "git ls-remote \"$REPO_URL\"" >/dev/null || die "Cannot reach $REPO_URL with the configured token."
}

ensure_repo_initialized() {
  if [ ! -d "$APP_DIR/.git" ]; then
    log "Initializing git repo in $APP_DIR"
    as_user "cd \"$APP_DIR\" && git init -q && (git remote add origin \"$REPO_URL\" 2>/dev/null || git remote set-url origin \"$REPO_URL\")"
    as_user "cd \"$APP_DIR\" && git fetch -q origin \"$BRANCH\" --prune && git checkout -q -f -B \"$BRANCH\" --track origin/\"$BRANCH\""
  else
    as_user "cd \"$APP_DIR\" && git remote set-url origin \"$REPO_URL\""
  fi
  as_user "cd \"$APP_DIR\" && git config core.fileMode false"
}

recover_git_state() {
  as_user "cd \"$APP_DIR\" && { git rebase --abort 2>/dev/null || true; git merge --abort 2>/dev/null || true; git cherry-pick --abort 2>/dev/null || true; git am --abort 2>/dev/null || true; git reset -q 2>/dev/null || true; }"
}

sync_to_origin() {
  log "Fetching origin/$BRANCH ..."
  as_user "cd \"$APP_DIR\" && git fetch -q origin \"$BRANCH\" --prune"
  recover_git_state
  local ts; ts="$(date +%Y%m%d-%H%M%S)"
  as_user "cd \"$APP_DIR\" && git diff \"origin/$BRANCH\" -- . > \"/tmp/${APP_SLUG}-drift.$ts.patch\" 2>/dev/null || true"
  [ -s "/tmp/${APP_SLUG}-drift.$ts.patch" ] && mv "/tmp/${APP_SLUG}-drift.$ts.patch" "$BACKUP_DIR/" && log "Saved local drift to $BACKUP_DIR/${APP_SLUG}-drift.$ts.patch"
  rm -f "/tmp/${APP_SLUG}-drift.$ts.patch"
  # Untracked runtime files (.env symlink, storage/*, vendor/) are untouched by reset --hard.
  as_user "cd \"$APP_DIR\" && git checkout -q -f -B \"$BRANCH\" \"origin/$BRANCH\" && git reset -q --hard \"origin/$BRANCH\""
  log "Now at: $(as_user "cd \"$APP_DIR\" && git log -1 --oneline")"
}

# --- 4. Environment file & secrets -----------------------------------------
rand_secret() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-"${1:-32}"; }

env_get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e 's/[[:space:]]*#.*$//' || true; }

env_set() {
  local key="$1" val="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV_FILE"
  else
    echo "${key}=${val}" >> "$ENV_FILE"
  fi
}

ensure_env() {
  if [ ! -f "$ENV_FILE" ]; then
    log "Creating $ENV_FILE (first deploy)"
    cat > "$ENV_FILE" <<EOF
APP_NAME="McNutt Finance"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${DOMAIN}
LOG_CHANNEL=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=30
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=$(rand_secret 32)
SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_COOKIE=__Host-mcfin_session
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
TRUSTED_PROXIES=
AUTH_DRIVER=mcnutt
AUTH_LOGIN_BASE=https://login.mcnutt.cloud
AUTH_APP_ID=
AUTH_APP_SECRET=
AUTH_EXPECTED_ISSUER=https://login.mcnutt.cloud
AUTH_VALIDATE_ENDPOINT=https://login.mcnutt.cloud/api/validate.php
AUTH_LOGIN_URL_TEMPLATE="{login_base}/?return_url={return_url}&app_id={app_id}"
AUTH_LOGOUT_URL_TEMPLATE="{login_base}/logout_confirm.php?token={token}&return_url={return_url}"
AUTH_REVALIDATE_SECONDS=300
AUTH_EMAIL_IS_VERIFIED=false
AUTH_BUSINESS_CREATION=all
FINANCE_PLATFORM_ADMINS=
NOTIFICATIONS_ENABLED=true
NOTIFICATIONS_DRIVER=mcnutt
NOTIFICATIONS_BASE_URL=https://notifications.mcnutt.cloud
NOTIFICATIONS_API_TOKEN=
NOTIFICATIONS_FROM_EMAIL=
NOTIFICATIONS_APPROVED_SENDERS=
NOTIFICATIONS_PROVIDER=
FINANCE_PRIVATE_STORAGE=${DATA_DIR}/private
FINANCE_BACKUP_STATUS_FILE=${DATA_DIR}/backup-status.json
EOF
  fi
  if [ -z "$(env_get APP_KEY)" ]; then
    env_set APP_KEY "base64:$(openssl rand -base64 32)"
    log "Generated APP_KEY. Back up ${ENV_FILE} separately — it is required to decrypt stored secrets and backups."
  fi
  [ -n "$(env_get DB_PASSWORD)" ] || env_set DB_PASSWORD "$(rand_secret 32)"
  chown root:"$RUN_AS" "$ENV_FILE"; chmod 640 "$ENV_FILE"
  ln -sfn "$ENV_FILE" "$APP_DIR/.env"
  chown -h "$RUN_AS:$RUN_AS" "$APP_DIR/.env"
}

# --- 5. Database (scoped to one DB + one user) -------------------------------
ensure_database() {
  local db user pass
  db="$(env_get DB_DATABASE)"; user="$(env_get DB_USERNAME)"; pass="$(env_get DB_PASSWORD)"
  [[ "$db" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "Invalid DB_DATABASE '$db'"
  [[ "$user" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "Invalid DB_USERNAME '$user'"
  [[ "$pass" =~ ^[A-Za-z0-9]{16,}$ ]] || die "DB_PASSWORD must be alphanumeric (>=16 chars) for safe provisioning."
  if ! $MYSQL_ADMIN -e "SELECT 1" >/dev/null 2>&1; then
    die "Cannot connect to MySQL/MariaDB as admin. Set MYSQL_ADMIN (e.g. MYSQL_ADMIN='mysql --defaults-file=/root/.my.cnf')."
  fi
  log "Ensuring database '$db' and user '$user'@'localhost' ($($MYSQL_ADMIN -N -e 'SELECT VERSION()'))"
  $MYSQL_ADMIN <<SQL
CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${pass}';
ALTER USER '${user}'@'localhost' IDENTIFIED BY '${pass}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON \`${db}\`.* TO '${user}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

# --- 6. Composer & build ----------------------------------------------------
ensure_composer() {
  local phar="$OPT_DIR/bin/composer.phar"
  if [ ! -f "$phar" ]; then
    log "Installing Composer to $phar (not system-wide)"
    local tmp; tmp="$(mktemp -d)"
    curl -fsSL https://composer.github.io/installer.sig -o "$tmp/installer.sig"
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    [ "$(/usr/bin/php${PHP_VERSION} -r "echo hash_file('sha384', '$tmp/composer-setup.php');")" = "$(cat "$tmp/installer.sig")" ] || die "Composer installer checksum mismatch"
    /usr/bin/php${PHP_VERSION} "$tmp/composer-setup.php" --quiet --install-dir="$OPT_DIR/bin" --filename=composer.phar
    rm -rf "$tmp"
  fi
  chmod 755 "$phar"
}

build_app() {
  export COMPOSER_HOME="$DATA_DIR/composer"
  log "composer install (production, PHP ${PHP_VERSION})"
  as_user "cd \"$APP_DIR\" && COMPOSER_HOME=\"$COMPOSER_HOME\" /usr/bin/php${PHP_VERSION} \"$OPT_DIR/bin/composer.phar\" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --classmap-authoritative"
  if [ "$BUILD_ASSETS" = "1" ]; then
    local arch bin="$OPT_DIR/bin/tailwindcss-${TAILWIND_VERSION}"
    case "$(uname -m)" in x86_64) arch=linux-x64 ;; aarch64|arm64) arch=linux-arm64 ;; *) die "Unsupported arch for Tailwind CLI" ;; esac
    [ -x "$bin" ] || { curl -fsSL -o "$bin" "https://github.com/tailwindlabs/tailwindcss/releases/download/${TAILWIND_VERSION}/tailwindcss-${arch}"; chmod 755 "$bin"; }
    log "Building CSS with Tailwind standalone CLI ${TAILWIND_VERSION} (no Node runtime)"
    as_user "cd \"$APP_DIR\" && \"$bin\" -c tailwind.config.js -i resources/css/app.css -o public/css/app.css --minify"
  fi
  [ -s "$APP_DIR/public/css/app.css" ] || die "public/css/app.css missing — run with BUILD_ASSETS=1 or commit the compiled asset."
  [ -s "$APP_DIR/public/js/app.js" ] || die "public/js/app.js missing."
}

fix_perms() {
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR"
  find "$APP_DIR" -path "$APP_DIR/.git" -prune -o -type d -exec chmod 755 {} +
  find "$APP_DIR" -path "$APP_DIR/.git" -prune -o -type f -exec chmod 644 {} +
  mkdir -p "$APP_DIR/storage/framework/"{cache,sessions,views} "$APP_DIR/storage/logs" "$APP_DIR/storage/fonts" "$APP_DIR/bootstrap/cache"
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  chmod -R 2775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  chown -h "$RUN_AS:$RUN_AS" "$APP_DIR/.env"
}

artisan() { as_user "cd \"$APP_DIR\" && /usr/bin/php${PHP_VERSION} artisan $*"; }

migrate_and_cache() {
  artisan "config:clear" >/dev/null
  log "Running migrations (non-destructive)"
  artisan "migrate --force"
  artisan "finance:bootstrap"
  artisan "config:cache" >/dev/null
  artisan "route:cache" >/dev/null
  artisan "view:cache" >/dev/null
  artisan "finance:preflight --no-network" || warn "Preflight reported problems (see above). The site will still be deployed."
}

# --- 7. PHP-FPM pool (dedicated, does not touch other pools) -----------------
ensure_fpm_pool() {
  local pool="/etc/php/${PHP_VERSION}/fpm/pool.d/${FPM_POOL_NAME}.conf" backup=""
  [ -f "$pool" ] && backup="$(mktemp)" && cp -a "$pool" "$backup"
  cat > "$pool" <<EOF
; Managed by ${APP_SLUG} deploy.sh — dedicated pool for ${DOMAIN}
[${FPM_POOL_NAME}]
user = ${RUN_AS}
group = ${RUN_AS}
listen = ${FPM_SOCK}
listen.owner = ${RUN_AS}
listen.group = ${RUN_AS}
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 120s
catch_workers_output = yes
php_admin_value[error_log] = ${LOG_DIR}/php-fpm.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size] = 16M
php_admin_value[max_execution_time] = 120
php_admin_value[session.cookie_secure] = 1
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
EOF
  if ! "/usr/sbin/php-fpm${PHP_VERSION}" -t >/dev/null 2>&1; then
    warn "php-fpm config test failed; rolling back our pool file"
    if [ -n "$backup" ]; then mv "$backup" "$pool"; else rm -f "$pool"; fi
    "/usr/sbin/php-fpm${PHP_VERSION}" -t || true
    die "PHP-FPM validation failed."
  fi
  rm -f "$backup"
  systemctl reload "php${PHP_VERSION}-fpm"
  for _ in 1 2 3 4 5; do [ -S "$FPM_SOCK" ] && break; sleep 1; done
  [ -S "$FPM_SOCK" ] || die "PHP-FPM socket $FPM_SOCK did not appear."
}

# --- 8. Apache vhost + Let's Encrypt ----------------------------------------
ensure_apache_modules() {
  local changed=0 m
  for m in proxy proxy_fcgi setenvif headers ssl rewrite; do
    if ! a2query -q -m "$m" 2>/dev/null; then a2enmod -q "$m" >/dev/null; changed=1; fi
  done
  [ "$changed" = "1" ] && log "Enabled Apache modules needed by this vhost (additive)."
  return 0
}

write_vhost() {
  local with_ssl="$1"
  local common
  common=$(cat <<EOF
    ServerName ${DOMAIN}
    DocumentRoot ${APP_DIR}/public
    Alias /.well-known/acme-challenge/ ${ACME_ROOT}/.well-known/acme-challenge/
    <Directory ${ACME_ROOT}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
EOF
)
  {
    echo "# Managed by ${APP_SLUG} deploy.sh — do not edit by hand (re-run deploy.sh)."
    echo "<VirtualHost *:80>"
    echo "$common"
    if [ "$with_ssl" = "1" ]; then
      cat <<EOF
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://${DOMAIN}%{REQUEST_URI} [R=301,L]
EOF
    else
      echo "    # TLS not yet issued: serve a placeholder only (the app requires HTTPS)."
      echo "    <Directory ${APP_DIR}/public>"
      echo "        Require all denied"
      echo "    </Directory>"
    fi
    echo "    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}-error.log"
    echo "    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}-access.log ${APP_SLUG}_safe"
    echo "</VirtualHost>"
    if [ "$with_ssl" = "1" ]; then
      cat <<EOF

<VirtualHost *:443>
${common}
    SSLEngine on
    SSLCertificateFile ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile ${CERT_DIR}/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off

    <Directory ${APP_DIR}/public>
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:${FPM_SOCK}|fcgi://${APP_SLUG}/"
    </FilesMatch>
    <Proxy "fcgi://${APP_SLUG}/">
        ProxySet timeout=130
    </Proxy>
    LimitRequestBody 20971520
    ServerSignature Off

    # Never log bearer links or SSO callback data: query strings are dropped everywhere,
    # and token-bearing paths are written with the token replaced.
    SetEnvIf Request_URI "^/(i|c|invite)/[A-Za-z0-9_-]{40,}" ${APP_TAG}_redact
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}-access.log ${APP_SLUG}_safe env=!${APP_TAG}_redact
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}-access.log ${APP_TAG}_redacted env=${APP_TAG}_redact
</VirtualHost>
EOF
    fi
  } > "$VHOST_FILE.new"
  # Log formats are defined in their own conf so they exist before the vhost references them.
  cat > "/etc/apache2/conf-available/${APP_SLUG}-logformat.conf" <<EOF
# Managed by ${APP_SLUG} deploy.sh
LogFormat "%h %l %u %t \"%m %U %H\" %>s %b \"-\" \"%{User-Agent}i\" %D" ${APP_SLUG}_safe
LogFormat "%h %l %u %t \"%m [redacted-link] %H\" %>s %b \"-\" \"%{User-Agent}i\" %D" ${APP_TAG}_redacted
EOF
}

apply_vhost() {
  local with_ssl="$1" backup=""
  write_vhost "$with_ssl"
  [ -f "$VHOST_FILE" ] && backup="$(mktemp)" && cp -a "$VHOST_FILE" "$backup"
  mv "$VHOST_FILE.new" "$VHOST_FILE"
  a2enconf -q "${APP_SLUG}-logformat" >/dev/null
  a2ensite -q "${DOMAIN}" >/dev/null
  if ! apache2ctl configtest >/dev/null 2>&1; then
    warn "apache2ctl configtest failed — rolling back ${VHOST_FILE}"
    if [ -n "$backup" ]; then mv "$backup" "$VHOST_FILE"; else a2dissite -q "${DOMAIN}" >/dev/null || true; rm -f "$VHOST_FILE"; fi
    apache2ctl configtest || true
    die "Apache configuration invalid; other sites were not reloaded."
  fi
  rm -f "$backup"
  systemctl reload apache2
  log "Apache vhost for ${DOMAIN} active (TLS: $([ "$with_ssl" = 1 ] && echo yes || echo pending))."
}

write_letsencrypt_script() {
  cat > "$LE_SCRIPT" <<EOF
#!/usr/bin/env bash
# Managed by ${APP_SLUG} deploy.sh — obtain/renew the TLS certificate for ${DOMAIN} via webroot.
# Non-invasive: uses 'certonly --webroot' so certbot never edits Apache configs.
set -euo pipefail
DOMAIN="${DOMAIN}"
EMAIL="${LE_EMAIL}"
WEBROOT="${ACME_ROOT}"
[ "\$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
PUBLIC_IP="\$(curl -fsS4 --max-time 5 https://api.ipify.org || true)"
DNS_IP="\$(getent ahostsv4 "\$DOMAIN" | awk 'NR==1{print \$1}')"
if [ -n "\$PUBLIC_IP" ] && [ -n "\$DNS_IP" ] && [ "\$PUBLIC_IP" != "\$DNS_IP" ]; then
  echo "[WARN] \$DOMAIN resolves to \$DNS_IP but this server's public IP looks like \$PUBLIC_IP. Validation may fail."
fi
certbot certonly --webroot -w "\$WEBROOT" -d "\$DOMAIN" \\
  --email "\$EMAIL" --agree-tos --no-eff-email --non-interactive \\
  --keep-until-expiring --cert-name "\$DOMAIN" \\
  --deploy-hook "apache2ctl configtest && systemctl reload apache2"
echo "[INFO] Certificate ready: /etc/letsencrypt/live/\$DOMAIN/"
EOF
  chmod 750 "$LE_SCRIPT"
}

ensure_tls() {
  write_letsencrypt_script
  if [ -f "$CERT_DIR/fullchain.pem" ]; then
    log "Existing certificate found for ${DOMAIN}."
    return 0
  fi
  if [ "$SKIP_LETSENCRYPT" = "1" ]; then
    warn "SKIP_LETSENCRYPT=1 — run ${LE_SCRIPT} once DNS for ${DOMAIN} points here, then re-run deploy.sh."
    return 1
  fi
  log "Requesting Let's Encrypt certificate via ${LE_SCRIPT}"
  if "$LE_SCRIPT"; then return 0; fi
  warn "Certificate request failed. Fix DNS/firewall (port 80) and run: sudo ${LE_SCRIPT} && sudo $0"
  return 1
}

ensure_cert_renewal() {
  if systemctl list-timers --all 2>/dev/null | grep -q certbot || [ -f /etc/cron.d/certbot ]; then
    log "certbot automatic renewal is already scheduled by the system package."
  else
    echo "17 3,15 * * * root certbot renew --cert-name ${DOMAIN} --quiet --deploy-hook 'apache2ctl configtest && systemctl reload apache2'" > "/etc/cron.d/${APP_SLUG}-certbot"
    chmod 644 "/etc/cron.d/${APP_SLUG}-certbot"
    log "Installed renewal cron for ${DOMAIN} only (/etc/cron.d/${APP_SLUG}-certbot)."
  fi
}

# --- 9. Cron, backups, logrotate --------------------------------------------
write_backup_scripts() {
  [ -f "$ETC_DIR/backup.key" ] || { openssl rand -base64 48 > "$ETC_DIR/backup.key"; log "Generated backup encryption key $ETC_DIR/backup.key — COPY IT OFFSITE."; }
  chown root:root "$ETC_DIR/backup.key"; chmod 600 "$ETC_DIR/backup.key"

  cat > "$BACKUP_SCRIPT" <<EOF
#!/usr/bin/env bash
# Managed by ${APP_SLUG} deploy.sh — encrypted daily backup of the ${APP_SLUG} database and private files.
set -euo pipefail
umask 077
ENV_FILE="${ENV_FILE}"; DEST="\${BACKUP_DEST:-${BACKUP_DIR}}"; KEY="${ETC_DIR}/backup.key"
STATUS="${DATA_DIR}/backup-status.json"; RETENTION="\${BACKUP_RETENTION_DAYS:-${BACKUP_RETENTION_DAYS}}"
get() { grep -E "^\$1=" "\$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"\$//'; }
TS="\$(date -u +%Y%m%dT%H%M%SZ)"; WORK="\$(mktemp -d)"; trap 'rm -rf "\$WORK"' EXIT
fail() { echo "{\"ok\":false,\"finished_at\":\"\$(date -u +%FT%TZ)\",\"error\":\"\$1\"}" > "\$STATUS"; chmod 644 "\$STATUS"; echo "[ERROR] \$1" >&2; exit 1; }
mkdir -p "\$DEST"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "\$(get DB_USERNAME)" "\$(get DB_PASSWORD)" "\$(get DB_HOST)" > "\$WORK/my.cnf"
mysqldump --defaults-extra-file="\$WORK/my.cnf" --single-transaction --routines --no-tablespaces "\$(get DB_DATABASE)" > "\$WORK/database.sql" || fail "mysqldump failed"
tar -C "\$(dirname "\$(get FINANCE_PRIVATE_STORAGE)")" -cf "\$WORK/private.tar" "\$(basename "\$(get FINANCE_PRIVATE_STORAGE)")" || fail "tar failed"
cp "\$ENV_FILE" "\$WORK/finance.env"
tar -C "\$WORK" -czf "\$WORK/bundle.tgz" database.sql private.tar finance.env
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass file:"\$KEY" -in "\$WORK/bundle.tgz" -out "\$DEST/${APP_SLUG}-\$TS.tgz.enc" || fail "encryption failed"
sha256sum "\$DEST/${APP_SLUG}-\$TS.tgz.enc" > "\$DEST/${APP_SLUG}-\$TS.tgz.enc.sha256"
find "\$DEST" -name '${APP_SLUG}-*.tgz.enc*' -mtime +"\$RETENTION" -delete
SIZE="\$(stat -c %s "\$DEST/${APP_SLUG}-\$TS.tgz.enc")"
echo "{\"ok\":true,\"finished_at\":\"\$(date -u +%FT%TZ)\",\"file\":\"${APP_SLUG}-\$TS.tgz.enc\",\"bytes\":\$SIZE}" > "\$STATUS"; chmod 644 "\$STATUS"
echo "[INFO] Backup written: \$DEST/${APP_SLUG}-\$TS.tgz.enc (\$SIZE bytes)"
# Optional offsite copy: set OFFSITE_RSYNC_TARGET (e.g. user@host:/backups/${APP_SLUG}/)
if [ -n "\${OFFSITE_RSYNC_TARGET:-}" ]; then rsync -a "\$DEST/${APP_SLUG}-\$TS.tgz.enc"* "\$OFFSITE_RSYNC_TARGET" || fail "offsite copy failed"; fi
EOF
  chmod 750 "$BACKUP_SCRIPT"

  cat > "$RESTORE_CHECK_SCRIPT" <<EOF
#!/usr/bin/env bash
# Managed by ${APP_SLUG} deploy.sh — restore drill: decrypts the newest (or given) backup into a
# scratch database, verifies the ledger balances and row counts, then drops the scratch database.
# Production data is never touched.
set -euo pipefail
umask 077
KEY="${ETC_DIR}/backup.key"; DEST="${BACKUP_DIR}"; MYSQL_ADMIN="\${MYSQL_ADMIN:-${MYSQL_ADMIN}}"
FILE="\${1:-\$(ls -1t "\$DEST"/${APP_SLUG}-*.tgz.enc | head -1)}"
SCRATCH="${DB_NAME}_restorecheck"
WORK="\$(mktemp -d)"; trap 'rm -rf "\$WORK"; \$MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \\\`\$SCRATCH\\\`" >/dev/null 2>&1 || true' EXIT
echo "[INFO] Verifying checksum of \$FILE"; sha256sum -c "\$FILE.sha256"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:"\$KEY" -in "\$FILE" -out "\$WORK/bundle.tgz"
tar -C "\$WORK" -xzf "\$WORK/bundle.tgz"
\$MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \\\`\$SCRATCH\\\`; CREATE DATABASE \\\`\$SCRATCH\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
\$MYSQL_ADMIN "\$SCRATCH" < "\$WORK/database.sql"
echo "[INFO] Restored into scratch database \$SCRATCH"
\$MYSQL_ADMIN -N "\$SCRATCH" -e "SELECT 'businesses', COUNT(*) FROM businesses UNION ALL SELECT 'invoices', COUNT(*) FROM invoices UNION ALL SELECT 'journal_entries', COUNT(*) FROM journal_entries UNION ALL SELECT 'private_documents', COUNT(*) FROM private_documents;"
UNBAL="\$(\$MYSQL_ADMIN -N "\$SCRATCH" -e "SELECT COUNT(*) FROM (SELECT business_id FROM journal_lines GROUP BY business_id HAVING SUM(debit_cents) <> SUM(credit_cents)) t;")"
[ "\$UNBAL" = "0" ] || { echo "[ERROR] \$UNBAL business ledger(s) out of balance in the backup"; exit 1; }
tar -tf "\$WORK/private.tar" >/dev/null && echo "[INFO] Private file archive readable (\$(tar -tf "\$WORK/private.tar" | wc -l) entries)"
grep -q '^APP_KEY=base64:' "\$WORK/finance.env" && echo "[INFO] Environment (APP_KEY) present in bundle"
echo "[OK] Restore drill passed for \$(basename "\$FILE"). Record the date in docs/BACKUP_RESTORE.md."
EOF
  chmod 750 "$RESTORE_CHECK_SCRIPT"
}

ensure_cron() {
  cat > "$CRON_FILE" <<EOF
# Managed by ${APP_SLUG} deploy.sh
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Laravel scheduler: outbox, recurring billing, reminders, maintenance (bounded runs, overlap-protected)
* * * * * ${RUN_AS} cd ${APP_DIR} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> ${LOG_DIR}/cron.log 2>&1
# Encrypted daily backup (DB + private files + env), ${BACKUP_RETENTION_DAYS}-day retention
30 2 * * * root ${BACKUP_SCRIPT} >> ${LOG_DIR}/backup.log 2>&1
EOF
  chmod 644 "$CRON_FILE"
  cat > "/etc/logrotate.d/${APP_SLUG}" <<EOF
${LOG_DIR}/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF
}

# --- 10. Verify -------------------------------------------------------------
health_check() {
  local scheme=https url code
  [ -f "$CERT_DIR/fullchain.pem" ] || { warn "No TLS yet — skipping HTTPS health check."; return 0; }
  url="${scheme}://${DOMAIN}/health"
  code="$(curl -s -o /tmp/${APP_SLUG}-health.json -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "$url" || true)"
  if [ "$code" = "200" ] || [ "$code" = "503" ]; then
    log "Health endpoint HTTP ${code}: $(cat /tmp/${APP_SLUG}-health.json)"
  else
    warn "Health check returned HTTP ${code}. See ${LOG_DIR}/php-fpm.log and ${APP_DIR}/storage/logs."
  fi
  rm -f "/tmp/${APP_SLUG}-health.json"
}

summary() {
  local missing=()
  for k in AUTH_APP_ID AUTH_APP_SECRET NOTIFICATIONS_API_TOKEN NOTIFICATIONS_FROM_EMAIL; do [ -n "$(env_get "$k")" ] || missing+=("$k"); done
  echo
  echo "==================================================================="
  echo " McNutt Finance deployed to ${APP_DIR}"
  echo "   URL:          https://${DOMAIN}"
  echo "   Env file:     ${ENV_FILE}   (back up offsite with ${ETC_DIR}/backup.key)"
  echo "   Private data: ${DATA_DIR}/private"
  echo "   TLS script:   ${LE_SCRIPT}"
  echo "   Backups:      ${BACKUP_SCRIPT}  (daily 02:30)  ·  drill: ${RESTORE_CHECK_SCRIPT}"
  echo "   Cron:         ${CRON_FILE}"
  if [ "${#missing[@]}" -gt 0 ]; then
    echo
    echo " ACTION REQUIRED — set these in ${ENV_FILE}, then re-run this script:"
    for k in "${missing[@]}"; do echo "   - ${k}"; done
    echo "   (Register app '${DOMAIN}' in McNutt Cloud with callback https://${DOMAIN}/auth/callback)"
  fi
  echo "==================================================================="
}

main() {
  require_root
  detect_php
  ensure_packages
  ensure_dirs
  ensure_askpass_and_token_perms
  auth_smoke_test
  ensure_repo_initialized
  sync_to_origin
  ensure_env
  ensure_database
  ensure_composer
  build_app
  fix_perms
  migrate_and_cache
  fix_perms
  ensure_fpm_pool
  ensure_apache_modules
  if [ -f "$CERT_DIR/fullchain.pem" ]; then
    apply_vhost 1
    write_letsencrypt_script
  else
    apply_vhost 0
    if ensure_tls; then apply_vhost 1; fi
  fi
  [ -f "$CERT_DIR/fullchain.pem" ] && ensure_cert_renewal
  write_backup_scripts
  ensure_cron
  health_check
  summary
}

main "$@"
