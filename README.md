# McNutt Finance

Multi-business invoicing, payments, contracts/e-signature, recurring billing, expenses, bank reconciliation and double-entry bookkeeping for small service businesses. Server-rendered Laravel 12 monolith for a shared LAMP host: no Node server, no Redis, no Docker, no always-running worker.

> Production URL: `https://invoices.mcnutt.cloud` · Sign-in: McNutt Cloud SSO · Email: McNutt Cloud notification service

## What's in the box

| Area | Highlights |
|---|---|
| Businesses & access | Business chooser + URL-scoped context (`/b/{business}`), members, invitations, 5 default roles + custom roles with named permissions, ownership transfer, audited central-role → capability mappings |
| Clients & catalog | Clients, contacts, signers, duplicate detection, products/services, tax rates, vendors |
| Invoicing | Draft editor with live estimate + autosave + optimistic locking, issue-once with locked numbering, frozen snapshots, versioned PDFs (2 templates, offline fonts), credit notes, write-offs, void & reissue, statements, aging |
| Client experience | Tokenized invoice links (256-bit, hashed, expiring, revocable) → clean URL, Zelle/check instructions with copy buttons, PDF download, remittance slip, "I sent payment" claims |
| Payments | Claims ≠ payments; confirm/reject/clarify; manual receipts; undeposited funds → deposit → cleared; overpayments as customer credit; apply/unapply; refunds; returned-check reversal |
| Contracts | Templates with validated merge fields, immutable hashed versions, parallel/sequential signers, business countersigner, email OTP, consent, typed/drawn signatures, signed PDF + audit certificate, amendments, external uploads, milestones |
| Recurring | Weekly/monthly/quarterly/annual (every N), month-end clamping, idempotent per period, catch-up review queue, agreement gating, auto-issue/auto-send |
| Books | Balanced journal posting with idempotent source keys, closed periods, manual journals, cash-basis projection, P&L (cash/accrual, monthly), balance sheet, trial balance, GL, AR/AP aging, sales tax, sales/expense breakdowns, forecasts |
| Banking | Manual accounts, CSV import with mapping/preview/dedupe, match/categorize/exclude/undo, transfers & owner activity, statement reconciliation |
| Operations | Transactional email outbox with retry/backoff/ambiguity handling, cron heartbeats, automation monitor, audit log, encrypted backups + restore drill |

## Local development

```bash
# PHP 8.2+ with pdo_sqlite, mbstring, dom, gd, intl, zip
composer install
cp .env.example .env
# local overrides:
#   APP_ENV=local  APP_DEBUG=true  APP_URL=http://127.0.0.1:8000
#   DB_CONNECTION=sqlite  (and touch database/database.sqlite)
#   AUTH_DRIVER=dev  NOTIFICATIONS_DRIVER=log  SESSION_SECURE_COOKIE=false  SESSION_COOKIE=mcfin_session
#   FINANCE_PRIVATE_STORAGE=storage/app/private
php artisan key:generate
php artisan migrate
php artisan finance:bootstrap
php artisan finance:demo-seed --force-nonprod     # two businesses, shared + restricted members, sample data
php artisan serve
```

Open `http://127.0.0.1:8000/auth/dev-login` and sign in as `demo_owner` (both businesses) or `demo_bookkeeper` (one business, Accountant role). Development emails are written to `storage/logs/mail-dev.log`; process them with `php artisan finance:outbox`.

`AUTH_DRIVER=dev` and `NOTIFICATIONS_DRIVER=log` are refused at boot when `APP_ENV=production`.

### Tests

```bash
php artisan test            # SQLite in-memory (fast)
DB_CONNECTION=mysql DB_DATABASE=finance_test DB_USERNAME=... DB_PASSWORD=... php artisan test   # MySQL/MariaDB constraints
```

### Front-end assets

No Node runtime is needed. `public/css/app.css` is committed; rebuild after view changes with the Tailwind standalone CLI:

```bash
./scripts/build-assets.sh          # downloads tailwindcss v3.4.17 standalone binary to ./bin (gitignored)
```

JavaScript (`public/js/app.js`) is dependency-free progressive enhancement; all forms work without it. CSP is `script-src 'self'`.

## Production

One command on the server (idempotent, shared-host safe):

```bash
sudo ./deploy.sh
```

It pulls `main` from GitHub, installs only missing packages, creates the dedicated database/user, builds, migrates, writes a dedicated PHP-FPM pool and Apache vhost, obtains a Let's Encrypt certificate, installs cron + encrypted backups, and runs a health check. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

**Operator setup required before go-live** (see [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md)):
1. Register app `invoices.mcnutt.cloud` in McNutt Cloud with callback `https://invoices.mcnutt.cloud/auth/callback`; set `AUTH_APP_ID`/`AUTH_APP_SECRET`.
2. Set `NOTIFICATIONS_API_TOKEN` and an approved `NOTIFICATIONS_FROM_EMAIL`.
3. Confirm the login redirect URL shape against the real `client_helper` ([docs/AUTH_INTEGRATION.md](docs/AUTH_INTEGRATION.md)).
4. Copy `/etc/mcnutt-finance/finance.env` and `/etc/mcnutt-finance/backup.key` offsite; run the restore drill.

## Documentation

[Architecture](docs/ARCHITECTURE.md) · [Data model](docs/DATA_MODEL.md) · [Auth integration](docs/AUTH_INTEGRATION.md) · [Integrations](docs/INTEGRATIONS.md) · [Permissions](docs/PERMISSIONS.md) · [Routes](docs/ROUTES.md) · [Accounting policies](docs/ACCOUNTING_POLICIES.md) · [Public link security](docs/PUBLIC_LINK_SECURITY.md) · [Signature evidence](docs/SIGNATURE_EVIDENCE.md) · [Cron & queues](docs/CRON_AND_QUEUES.md) · [Deployment](docs/DEPLOYMENT.md) · [Backup & restore](docs/BACKUP_RESTORE.md) · [Migration](docs/MIGRATION.md) · [Acceptance tests](docs/ACCEPTANCE_TESTS.md) · [Implementation status](docs/IMPLEMENTATION_STATUS.md) · [Roadmap](docs/ROADMAP.md)
