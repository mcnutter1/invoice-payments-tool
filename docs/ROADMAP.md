# Roadmap

## Near term (close production blockers)
* Live sign-in test with a dedicated app registration; add callback `state` binding if the provider adds support.
* Notification attachments (if the service adds them) and signed delivery webhooks → `delivered`/`bounced` states.
* MySQL/MariaDB CI run, browser/accessibility test suite, first recorded restore drill.
* Saved report views; import of historical payment allocations.

## Later (spec §21 — not started; no placeholder buttons)
Automated bank feeds · verified bank/Zelle settlement adapters · card/ACH payment providers · payroll · automated sales-tax determination/remittance · 1099 preparation · inventory · multi-currency · advanced revenue recognition · time tracking · proposal/estimate conversion · OCR expense capture · budgets · cross-firm accountant collaboration · qualified/certificate-based signature providers.

Adapter boundaries already in place: `AuthGateway`, `NotificationGateway`, `PaymentService` claim/confirm split (for future settlement adapters), `BankingService` import pipeline (for feeds), `PdfRenderer`.
