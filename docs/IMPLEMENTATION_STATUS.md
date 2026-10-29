# Implementation status

As of 2026-10-29.

## Implemented
* McNutt Cloud SSO adapter (HMAC callback verification, replay protection, server-side validation, 5-minute revalidation, fail-closed outage, logout), local users keyed by central principal.
* Multiple businesses per user, business switcher, URL-scoped tenancy, invitations with approval, five default roles + custom roles, ownership transfer, last-owner protection, platform capability mappings.
* Clients, contacts, tax rates, products/services, vendors.
* Invoice editor (autosave, live estimate, server-authoritative math), numbering, issue/send, void, credit notes, write-offs, duplicate, immutable PDF versions (dompdf), branding/templates, statements.
* Secure public invoice page with PDF download, remittance slip, payment reporting (Zelle/check/ACH/other), linked signed agreement.
* Payments: record, confirm claims, allocate/unapply, deposits (undeposited funds), reversals/returned checks, refunds, customer credit.
* Expenses, vendor bills, bill payments, transfers, owner contributions/draws, loans, receipts upload.
* Double-entry ledger with idempotent postings, manual journals, period close/reopen, cash-basis projection.
* Reports (14): P&L cash/accrual, balance sheet, trial balance, general ledger, A/R aging + open invoices, customer statements, A/P aging, sales by client/item, expenses by category/vendor, cash flow summary, sales tax liability, payments received, journal drilldown; CSV export and print view.
* Banking: accounts, CSV import with mapping and dedupe, match/categorize/transfer/ignore, reconciliation sessions.
* Contracts: templates with merge fields, versions, amendments, external uploads, recipients with order, email OTP, consent, typed/drawn signatures, countersigning, signed PDF + audit certificate.
* Recurring billing with agreement gating, catch-up review queue, milestones, auto-send, reminders.
* Notification outbox with retry/unknown/review handling, automation monitor, audit log, dashboard, global search.
* Data import (clients, catalog, opening A/R, expenses), demo seed (`finance:demo-seed --force-nonprod`).
* `deploy.sh`: idempotent install/update, scoped DB, dedicated FPM pool, vhost, Let's Encrypt script, cron, logrotate, encrypted backups, restore drill, preflight.

## Validation performed
* `php artisan test`: 38 tests / 317 assertions passing (SQLite).
* Browser check of dashboard, invoice editor, public invoice page; rendered invoice, certificate and multi-page (70-line, 6-page) PDFs inspected.
* `bash -n deploy.sh`.

## Production blockers / not verified
1. **McNutt Cloud auth**: adapter aligned with the official client helper samples (signature format, login/logout/access-denied URLs, validate response). Still needs a live sign-in with a dedicated app registration (AUTH_INTEGRATION.md).
2. **PDF attachments**: the notification API has no attachment support. Per owner decision, emails carry a secure PDF download link instead. Proposed API extension documented in INTEGRATIONS.md.
3. **Delivery status**: no delivery webhooks exist; the app reports "accepted by mail service" only.
4. **Operator secrets** needed: `AUTH_APP_ID`, `AUTH_APP_SECRET`, `NOTIFICATIONS_API_TOKEN`, `NOTIFICATIONS_FROM_EMAIL`; register callback `https://invoices.mcnutt.cloud/auth/callback`.
5. **Not executed yet**: test suite on MySQL/MariaDB, first server deploy, Let's Encrypt issuance, restore drill, real notification sandbox send.
6. **Not implemented**: saved report views, automated import of historical payment allocations, automated browser/accessibility tests.
