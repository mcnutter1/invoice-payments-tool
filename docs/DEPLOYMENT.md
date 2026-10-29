# Deployment

Target: an existing Ubuntu/Debian server with Apache 2.4, MySQL 8 or MariaDB 10.6+, and other sites already running. `deploy.sh` is idempotent — run it for the first install and every update.

## Prerequisites (one time)
1. DNS: `invoices.mcnutt.cloud` A/AAAA → this server; port 80 and 443 open.
2. GitHub read access, same convention as the other apps:
   * `/etc/secrets/github_pat` — one line PAT with read access to `mcnutter1/invoice-payments-tool`.
   * `/usr/local/bin/git-askpass-github.sh` — the script prints the exact contents if missing.
3. MySQL/MariaDB admin access for root via socket (Ubuntu default). Otherwise pass `MYSQL_ADMIN='mysql --defaults-file=/root/.my.cnf'`.
4. PHP ≥ 8.2 installed (`/usr/bin/php8.2|8.3|8.4`). If none, `ALLOW_PHP_INSTALL=1` installs PHP 8.3 **side by side** and restores the previous default `php`.

## Run
```bash
curl -fsSL -H "Authorization: token $(sudo cat /etc/secrets/github_pat)" \
  https://raw.githubusercontent.com/mcnutter1/invoice-payments-tool/main/deploy.sh -o /tmp/deploy-finance.sh
sudo bash /tmp/deploy-finance.sh
# later updates:
sudo /var/www/invoices.mcnutt.cloud/deploy.sh
```

Useful overrides: `DOMAIN`, `LE_EMAIL`, `BRANCH`, `DB_NAME`, `DB_USER`, `PHP_VERSION`, `SKIP_LETSENCRYPT=1`, `BUILD_ASSETS=1`, `MYSQL_ADMIN=…`.

## What it does
1. Detects PHP; installs only missing packages (php-fpm and extensions for that version, certbot, git, mysql client).
2. Creates `/etc/mcnutt-finance` (env, backup key), `/var/lib/mcnutt-finance` (private files, ACME webroot, composer cache), `/var/log/mcnutt-finance`, `/opt/mcnutt-finance/bin` (composer.phar, optional Tailwind CLI).
3. Mirrors `origin/main` into `/var/www/invoices.mcnutt.cloud` (`git reset --hard`; untracked runtime files are kept, local drift saved as a patch).
4. First run: writes `/etc/mcnutt-finance/finance.env` with a generated `APP_KEY` and DB password; symlinks it to `.env`.
5. `CREATE DATABASE IF NOT EXISTS mcnutt_finance` + `CREATE USER IF NOT EXISTS 'mcnutt_finance'@'localhost'` with privileges on that database only.
6. `composer install --no-dev` (checksum-verified installer), verifies compiled assets, `migrate --force` (never fresh/reset), `finance:bootstrap`, config/route/view cache, `finance:preflight`.
7. Dedicated PHP-FPM pool `/etc/php/X.Y/fpm/pool.d/mcnutt-finance.conf` (socket `/run/php/mcnutt-finance.sock`), validated with `php-fpmX.Y -t` before `reload`.
8. Apache: enables needed modules if absent, writes only `/etc/apache2/sites-available/invoices.mcnutt.cloud.conf` and `conf-available/mcnutt-finance-logformat.conf`, runs `apache2ctl configtest` before `reload`, and rolls back its own file on failure.
9. TLS: writes `/usr/local/sbin/mcnutt-finance-letsencrypt.sh` (certbot `certonly --webroot`, never edits Apache files) and runs it if no certificate exists, then switches the vhost to HTTPS with HTTP→HTTPS redirect. Renewal uses the system certbot timer, or a cron limited to this certificate if none exists.
10. Cron (`/etc/cron.d/mcnutt-finance`), logrotate, backup and restore-drill scripts.
11. Health check `https://invoices.mcnutt.cloud/health` and a summary of missing secrets.

## After the first run
Edit `/etc/mcnutt-finance/finance.env`:
```
AUTH_APP_ID=…                # register app + callback https://invoices.mcnutt.cloud/auth/callback
AUTH_APP_SECRET=…
NOTIFICATIONS_API_TOKEN=…
NOTIFICATIONS_FROM_EMAIL=billing@mcnutt.cloud
FINANCE_PLATFORM_ADMINS=u_xxx   # optional: your central principal id
```
then re-run `sudo ./deploy.sh` (re-caches config). Verify with `sudo -u www-data php artisan finance:preflight`.

## Files owned by this app (safe to remove on uninstall)
`/var/www/invoices.mcnutt.cloud`, `/etc/mcnutt-finance`, `/var/lib/mcnutt-finance`, `/var/log/mcnutt-finance`, `/opt/mcnutt-finance`, `/var/backups/mcnutt-finance`, `/etc/apache2/sites-available/invoices.mcnutt.cloud.conf`, `/etc/apache2/conf-available/mcnutt-finance-logformat.conf`, `/etc/php/X.Y/fpm/pool.d/mcnutt-finance.conf`, `/etc/cron.d/mcnutt-finance*`, `/etc/logrotate.d/mcnutt-finance`, `/usr/local/sbin/mcnutt-finance-*.sh`, database `mcnutt_finance` and user `mcnutt_finance@localhost`.

## Rollback
Code: `cd /var/www/invoices.mcnutt.cloud && sudo -u www-data git reset --hard <previous-sha>` then re-run composer/cache steps (or set `BRANCH` to a tag). Database migrations are additive; restore from backup if a migration must be undone.
