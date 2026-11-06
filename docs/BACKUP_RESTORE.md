# Backup & restore

## Per-business S3 backups (in-app)
`finance:backups` runs hourly (`:05`). For every business with backups enabled it writes a **daily full** (first run each UTC day) or an **hourly delta** (rows changed since the previous point, plus the full key list of every table so deletions are captured). Objects are gzip + `APP_KEY`-encrypted and uploaded with SSE-S3 to `{prefix}/businesses/{business ulid}/snapshots/{Y/m/d}/{snapshot}-{full|delta}.bin`; private files are content-addressed once under `{prefix}/businesses/{ulid}/files/{sha256}.bin`.

* **Configure:** users with the SSO `admin` role (or listed in `FINANCE_PLATFORM_ADMINS`) open *Application settings* (`/platform/backups`): bucket, region, access key/secret (stored encrypted), optional S3-compatible endpoint, retention (deltas 7 d, fulls 35 d by default), which businesses are backed up and whether new businesses are enabled by default. IAM needs `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` on the prefix.
* **Explore / restore:** inside a business, *Settings → Backups* (`business.manage`): search any record across all backup points, see each version with a field-by-field diff against now and the previous version, preview backed-up PDFs/images, and restore a record (*overwrite*, *only if missing*, or *as a copy* for clients, contacts, products, vendors, tax rates, templates, invoices → new draft, recurring schedules → paused). A whole business can be rolled back to any point; a safety full backup is taken first, and audit logs, email history and signing evidence are kept. *Download export* gives the reconstructed point as JSON.
* **Limits:** a single-record overwrite of accounting records does not re-post ledger entries (use a business rollback for consistency). `APP_KEY` is required to read backups — keep the env backup below.
* **Storage classes:** hourly deltas default to S3 Standard (tiny, short-lived objects), daily fulls and files to **Glacier Instant Retrieval** (~$0.004/GB-month, millisecond reads, 90-day minimum). Glacier Flexible Retrieval/Deep Archive are deliberately not offered: they need hours to restore, which breaks previews and one-click restores. The latest key manifest is kept in the database, so hourly runs never read from S3.
* **Checks & alerts:** *Test connection & restore* writes with each storage class, confirms SSE, reads back, deletes, and rebuilds the latest backup, explaining AWS errors (wrong region, access denied, bad secret…). `finance:backups-weekly` (Mondays 15:00 UTC) restore-tests every business's latest backup and emails a summary; failed runs email immediately (repeated at most daily) and a recovery notice follows. Recipients: *Notification emails* setting, else all SSO admins. The settings page includes copy-paste IAM policy, bucket policy, lifecycle rules and AWS CLI commands scoped to the configured bucket/prefix.

## Server-level backup
## What is backed up
`/usr/local/sbin/mcnutt-finance-backup.sh` (cron 02:30 daily, root):
* `mysqldump --single-transaction` of the app database only,
* tar of private files (`FINANCE_PRIVATE_STORAGE`),
* the env file (contains `APP_KEY`, needed to decrypt tax IDs and queued delivery material),
bundled and encrypted with AES-256-CBC (PBKDF2, 200k iterations) using `/etc/mcnutt-finance/backup.key`, plus a `.sha256` file, in `/var/backups/mcnutt-finance`. Retention: 30 days (`BACKUP_RETENTION_DAYS`). Optional offsite copy: set `OFFSITE_RSYNC_TARGET` in the cron environment. Status is written to `/var/lib/mcnutt-finance/backup-status.json` and shown in the automation monitor.

**Keys:** store `/etc/mcnutt-finance/backup.key` and `/etc/mcnutt-finance/finance.env` in a separate secure location (password manager / offline). Without `backup.key` the backups cannot be decrypted.

Financial-document retention: the app never hard-deletes issued/signed/posted records; keep long-term copies of monthly backups per your accountant's retention advice (commonly 7 years).

## Restore drill (non-destructive)
```bash
sudo /usr/local/sbin/mcnutt-finance-restore-check.sh            # newest backup
sudo /usr/local/sbin/mcnutt-finance-restore-check.sh /var/backups/mcnutt-finance/<file>.tgz.enc
```
It verifies the checksum, decrypts, restores into a scratch database `mcnutt_finance_restorecheck`, prints row counts, verifies every business ledger balances, checks the file archive and env, then drops the scratch database.

Record drills here:

| Date | Backup file | Result | By |
|---|---|---|---|
| _pending first production drill_ | | | |

## Full restore (disaster recovery)
```bash
KEY=/secure/location/backup.key
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:$KEY -in mcnutt-finance-<ts>.tgz.enc -out bundle.tgz
tar -xzf bundle.tgz                     # database.sql, private.tar, finance.env
sudo install -o root -g www-data -m 640 finance.env /etc/mcnutt-finance/finance.env
sudo ./deploy.sh                        # recreates app, DB user/schema, vhost, cron (empty DB)
sudo mysql mcnutt_finance < database.sql
sudo tar -C /var/lib/mcnutt-finance -xf private.tar && sudo chown -R www-data:www-data /var/lib/mcnutt-finance/private
sudo -u www-data php /var/www/invoices.mcnutt.cloud/artisan migrate --force
sudo -u www-data php /var/www/invoices.mcnutt.cloud/artisan config:cache
```
Then open Reports → Trial balance and compare totals with the last known report.

Targets: RPO 24 h (daily backups), RTO 4 h — **not yet demonstrated**; they are met only after a successful production restore drill is recorded above.
