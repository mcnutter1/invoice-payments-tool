# Backup & restore

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
