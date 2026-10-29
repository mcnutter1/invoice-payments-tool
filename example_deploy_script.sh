#!/usr/bin/env bash
set -euo pipefail

# --- Config ---
REPO_URL="https://github.com/mcnutter1/seaside-weather-app.git"
BRANCH="v2"
APP_DIR="/var/www/weather.robertmcnutt.com"
RUN_AS="www-data"
ASKPASS="/usr/local/bin/git-askpass-github.sh"
TOKEN_FILE="/etc/secrets/github_pat"
BACKUP_DIR="/var/backups/weather-deploy"

# Files whose SERVER copy should survive a deploy (repo version is ignored for
# these). Leave empty to mirror GitHub exactly (recommended if you keep config
# in git). Example to keep the live config:  PRESERVE_LOCAL=( "config/app_config.json" )
PRESERVE_LOCAL=()

# Glob patterns (relative to APP_DIR) for SERVER-owned RUNTIME state that must
# survive a deploy. Matches both files and directories. Unlike PRESERVE_LOCAL
# (fixed paths), these expand at deploy time so per-app files are covered too.
#
# The admin grading editor writes these on the live server:
#   - config/grading_rules_v2*.json         (deployed + draft, per app)
#   - config/grading_rules_v2*.history/     (version snapshots + index.json)
# They are runtime state, NOT code. Without preserving them, a `git reset --hard`
# resets the DEPLOYED rules to the repo's committed copy, which desyncs the live
# grading from the editor's manifest (deployed_id points at a snapshot that no
# longer matches the on-disk file) and makes the "compare vs deployed" tools and
# the front-end V1/V2 badges show phantom differences.
PRESERVE_GLOBS=(
  "config/grading_rules_v2*.json"
  "config/grading_rules_v2*.history"
)

export GIT_ASKPASS="$ASKPASS"
export GIT_TERMINAL_PROMPT=0   # never fall back to interactive prompts

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "[ERROR] This script must be run with sudo/root."
    exit 1
  fi
}

ensure_dirs() {
  mkdir -p "$APP_DIR"
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR"
  chmod -R 0755 "$APP_DIR"
}

ensure_askpass_and_token_perms() {
  # ASKPASS script must exist and be executable by root and rx by www-data
  if [ ! -f "$ASKPASS" ]; then
    echo "[ERROR] ASKPASS helper not found at $ASKPASS"
    echo "Create it with:"
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
  chgrp www-data "$ASKPASS" || true
  chmod 750 "$ASKPASS"

  # Token file must exist and be readable by www-data (group), but not world
  if [ ! -f "$TOKEN_FILE" ]; then
    echo "[ERROR] Token file not found at $TOKEN_FILE"
    echo "Create it with one line containing your PAT, then:"
    echo "sudo chgrp -R www-data /etc/secrets && sudo chmod 710 /etc/secrets && sudo chmod 640 $TOKEN_FILE"
    exit 1
  fi
  chgrp -R www-data "$(dirname "$TOKEN_FILE")" || true
  chmod 710 "$(dirname "$TOKEN_FILE")"
  chmod 640 "$TOKEN_FILE"
}

as_user() {
  sudo -u "$RUN_AS" --preserve-env=GIT_ASKPASS,GIT_TERMINAL_PROMPT bash -lc "$*"
}

ensure_repo_initialized() {
  if [ ! -d "$APP_DIR/.git" ]; then
    echo "[INFO] Initializing git repo in existing directory (no clone): $APP_DIR"
    as_user "cd \"$APP_DIR\" && git init"
    as_user "cd \"$APP_DIR\" && (git remote add origin \"$REPO_URL\" 2>/dev/null || git remote set-url origin \"$REPO_URL\")"
    as_user "cd \"$APP_DIR\" && git fetch origin \"$BRANCH\" --prune"
    # Create/switch local branch to track origin/BRANCH (may fail if files conflict; resolve manually if so)
    as_user "cd \"$APP_DIR\" && git checkout -B \"$BRANCH\" --track origin/\"$BRANCH\"" || true
    as_user "cd \"$APP_DIR\" && git config core.fileMode false"
  else
    as_user "cd \"$APP_DIR\" && git remote set-url origin \"$REPO_URL\""
    as_user "cd \"$APP_DIR\" && git config core.fileMode false"
  fi
}

auth_smoke_test() {
  echo "[INFO] Testing GitHub auth via ASKPASS ..."
  as_user "git ls-remote \"$REPO_URL\"" >/dev/null
  echo "[INFO] Auth OK."
}

# Clear any interrupted git operation (rebase/merge/cherry-pick/am) and unmerged
# index entries so a deploy can never get wedged by a previous failed run.
recover_git_state() {
  echo "[INFO] Clearing any interrupted git operation (if present) ..."
  as_user "cd \"$APP_DIR\" && { \
    git rebase --abort 2>/dev/null || true; \
    git merge --abort 2>/dev/null || true; \
    git cherry-pick --abort 2>/dev/null || true; \
    git am --abort 2>/dev/null || true; \
    git reset -q 2>/dev/null || true; }"
}

# Snapshot live config + a drift patch, and stash copies of PRESERVE_LOCAL files.
backup_local() {
  mkdir -p "$BACKUP_DIR"
  chown "$RUN_AS:$RUN_AS" "$BACKUP_DIR"
  local ts; ts="$(date +%Y%m%d-%H%M%S)"

  # Start every deploy with a CLEAN staging area. The preserve/ tree is transient:
  # it is populated here and consumed by restore_local at the end of THIS deploy.
  # If it is left over from a previous run, `cp -a <dir> preserve/<dir>` copies the
  # directory INTO the existing one and creates a nested
  #   grading_rules_v2.history/grading_rules_v2.history/
  # which then propagates to the live tree on restore, silently reverting the
  # grading-rules version history (the stale top-level index.json wins).
  rm -rf "$BACKUP_DIR/preserve"

  # Always keep a safety copy of the live config.
  if [ -f "$APP_DIR/config/app_config.json" ]; then
    cp -a "$APP_DIR/config/app_config.json" "$BACKUP_DIR/app_config.$ts.json"
    echo "[INFO] Backed up config/app_config.json -> $BACKUP_DIR/app_config.$ts.json"
  fi

  # Record everything the server has that differs from origin (for your review).
  as_user "cd \"$APP_DIR\" && git diff \"origin/$BRANCH\" -- . > \"$BACKUP_DIR/local-drift.$ts.patch\" 2>/dev/null || true"

  # Preserve chosen files across the reset.
  if [ "${#PRESERVE_LOCAL[@]}" -gt 0 ]; then
    local f
    for f in "${PRESERVE_LOCAL[@]}"; do
      if [ -f "$APP_DIR/$f" ]; then
        mkdir -p "$BACKUP_DIR/preserve/$(dirname "$f")"
        cp -a "$APP_DIR/$f" "$BACKUP_DIR/preserve/$f"
      fi
    done
  fi

  # Preserve server-owned runtime state matched by globs (files + directories).
  if [ "${#PRESERVE_GLOBS[@]}" -gt 0 ]; then
    local g match rel
    shopt -s nullglob
    for g in "${PRESERVE_GLOBS[@]}"; do
      for match in "$APP_DIR"/$g; do
        rel="${match#"$APP_DIR"/}"
        mkdir -p "$BACKUP_DIR/preserve/$(dirname "$rel")"
        # Remove any stale destination first so `cp -a` can never nest a
        # directory copy inside a pre-existing directory of the same name.
        rm -rf "$BACKUP_DIR/preserve/$rel"
        cp -a "$match" "$BACKUP_DIR/preserve/$rel"
        echo "[INFO] Backed up runtime state $rel"
      done
    done
    shopt -u nullglob
  fi
}

restore_local() {
  if [ "${#PRESERVE_LOCAL[@]}" -gt 0 ]; then
    local f
    for f in "${PRESERVE_LOCAL[@]}"; do
      if [ -f "$BACKUP_DIR/preserve/$f" ]; then
        cp -a "$BACKUP_DIR/preserve/$f" "$APP_DIR/$f"
        chown "$RUN_AS:$RUN_AS" "$APP_DIR/$f"
        echo "[INFO] Preserved local $f (server copy kept)"
      fi
    done
  fi

  # Restore server-owned runtime state matched by globs (files + directories).
  if [ "${#PRESERVE_GLOBS[@]}" -gt 0 ]; then
    local g match rel dest
    shopt -s nullglob
    for g in "${PRESERVE_GLOBS[@]}"; do
      for match in "$BACKUP_DIR"/preserve/$g; do
        rel="${match#"$BACKUP_DIR"/preserve/}"
        dest="$APP_DIR/$rel"
        mkdir -p "$(dirname "$dest")"
        rm -rf "$dest"
        cp -a "$match" "$dest"
        chown -R "$RUN_AS:$RUN_AS" "$dest"
        echo "[INFO] Restored runtime state $rel (server copy kept)"
      done
    done
    shopt -u nullglob
  fi
}

# Deterministic mirror deploy: make the working tree exactly match origin/BRANCH.
# Never touches untracked runtime files (logs/, www/api/v2/static/*.json).
sync_to_origin() {
  echo "[INFO] Fetching origin/$BRANCH ..."
  as_user "cd \"$APP_DIR\" && git fetch origin \"$BRANCH\" --prune"

  recover_git_state
  backup_local

  echo "[INFO] Local changes vs origin before sync (if any):"
  as_user "cd \"$APP_DIR\" && git status --porcelain || true"

  echo "[INFO] Mirroring working tree to origin/$BRANCH (git reset --hard) ..."
  as_user "cd \"$APP_DIR\" && git checkout -f -B \"$BRANCH\" \"origin/$BRANCH\""
  as_user "cd \"$APP_DIR\" && git reset --hard \"origin/$BRANCH\""
  as_user "cd \"$APP_DIR\" && git branch --set-upstream-to=origin/\"$BRANCH\" \"$BRANCH\" 2>/dev/null || true"

  restore_local

  echo "[INFO] Now at:"
  as_user "cd \"$APP_DIR\" && git log -1 --oneline"
}

fix_perms() {
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR"
  find "$APP_DIR" -type d -exec chmod 755 {} \;
  find "$APP_DIR" -type f -exec chmod 644 {} \;
  chmod -R 2775 "$APP_DIR/www/api/v2/static"
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR/www/api/v2/static/"
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR/logs/"
  chmod -R 2775 "$APP_DIR/logs/"
  # Runtime state written by BOTH the web user (www-data via php-fpm) and the
  # every-minute cron (ubuntu): push_state.json, push_subscriptions.json,
  # push_deliveries.jsonl, push_control.json, etc. Must be group-writable +
  # setgid so new files inherit the www-data group no matter which user creates
  # them. Without this, the generic `chmod 644/755` above leaves data/ at 0755
  # www-data:www-data and the ubuntu cron can't write — so push de-dup/state
  # never persists (alerts repeat) and the delivery history stays empty.
  mkdir -p "$APP_DIR/data"
  chown -R "$RUN_AS:$RUN_AS" "$APP_DIR/data/"
  chmod -R 2775 "$APP_DIR/data/"
  usermod -aG www-data ubuntu
}

main() {
  require_root
  ensure_dirs
  ensure_askpass_and_token_perms
  auth_smoke_test
  ensure_repo_initialized
  sync_to_origin
  fix_perms
  # Optional: reload Apache if needed
  # systemctl reload apache2
  echo "[INFO] Deploy complete."
}

main "$@"