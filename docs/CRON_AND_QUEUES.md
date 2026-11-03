# Cron & queues

A single system cron line (installed by `deploy.sh` at `/etc/cron.d/mcnutt-finance`) runs the Laravel scheduler every minute as `www-data`:

```
* * * * * www-data cd /var/www/invoices.mcnutt.cloud && /usr/bin/php8.x artisan schedule:run >> /var/log/mcnutt-finance/cron.log 2>&1
```

| Command | Schedule | What it does | Bounds |
|---|---|---|---|
| `finance:outbox` | every minute | Sends queued emails via the notification gateway | `FINANCE_OUTBOX_BATCH` (25) rows, `FINANCE_CRON_MAX_SECONDS` (50 s), `withoutOverlapping(10)` |
| `finance:recurring` | every minute | Generates due recurring invoices | `FINANCE_RECURRING_BATCH` (50) schedules, 50 s, overlap lock |
| `finance:reminders` | daily 14:00 UTC | Client reminders every N days (per business, Settings → Reminders): unsigned agreements, unviewed invoices, past-due invoices | skips invoices with a fresh payment report; each reminder is audited |
| `finance:maintenance` | hourly | Expires signing envelopes, prunes callback nonces and stale CSV uploads | — |

Each command records `job_heartbeats` (last start/success/failure/error); the automation monitor and `/health` flag a stale scheduler (> 5 min).

## Outbox guarantees
* Messages are inserted in the same DB transaction as the business change, so nothing is sent for rolled-back work.
* A worker claims a row with an atomic `UPDATE … WHERE status='queued'`; concurrent runs never double-send.
* Before sending, the worker rechecks business status, invoice state, link revocation and envelope state; obsolete messages are `cancelled`.
* Rows stuck in `sending` > 10 min (worker died) become `delivery_unknown` and need review — never auto-resent. Exactly-once delivery is not promised.

## Recurring guarantees
* Each schedule run locks the schedule row; `schedule_runs` has a unique `(business, schedule, period)` key.
* Next run advances only after the invoice/outbox rows commit.
* After an outage, only the latest due period is generated automatically; older periods go to a "needs review" queue (create draft or skip).
* Agreement-linked schedules pause if the agreement is not executed, not yet effective, ended or terminated.
* Automation runs as the system actor with business-level authority (not the creator's membership) and rechecks business/schedule/agreement state at execution.

## Manual runs
```bash
sudo -u www-data php artisan finance:outbox
sudo -u www-data php artisan finance:recurring
sudo -u www-data php artisan schedule:list
```
