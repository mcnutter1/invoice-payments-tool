# Architecture

## Shape

Modular Laravel 12 monolith (PHP ≥ 8.2), server-rendered Blade + Tailwind (compiled ahead of time with the standalone CLI) + a single dependency-free `public/js/app.js` for progressive enhancement. MySQL 8 / MariaDB 10.6+ in production (SQLite for fast local tests). dompdf 3 renders PDFs offline with embedded DejaVu fonts.

**Deliberate deviation from the spec default (Inertia/Vue/TypeScript):** the host has no Node toolchain and the spec forbids requiring a Node server. Blade keeps the production build to `composer install` + one committed CSS file, keeps CSP strict (`script-src 'self'`, no eval), and lets every form work without JavaScript. The domain layer is UI-agnostic, so an Inertia front end can be added later without changing services.

No Docker, Redis, WebSockets, headless browser or long-running worker. Background work = one-minute cron → `php artisan schedule:run` → bounded, overlap-protected commands.

## Modules → code

| Module | Code |
|---|---|
| Identity | `Services/Auth/*` (AuthGateway adapter, McNutt + dev drivers, CentralSession), `Http/Middleware/EnsureCentralAuth` |
| Businesses & Access | `Services/Access/*`, `Support/Permissions`, `Http/Middleware/ResolveBusiness`, `RequirePermission`, `MemberController`, `InvitationController`, `PlatformController` |
| Clients / Catalog | `ClientController`, `CatalogController`, models `Client`, `Contact`, `Product`, `TaxRate`, `Vendor` |
| Invoicing | `Services/Invoicing/*` (calculator, service, presenter), `InvoiceController` |
| Payments | `Services/Payments/PaymentService`, `PaymentController`, `PublicInvoiceController::reportPayment` |
| Contracts & Signatures | `Services/Contracts/*`, `ContractController`, `PublicContractController` |
| Recurrence | `Services/Recurring/*`, `RecurringController`, `finance:recurring` |
| Expenses | `Services/Expenses/ExpenseService`, `ExpenseController` |
| Accounting & Reports | `Services/Accounting/{Ledger,CashProjection}`, `Services/Reports/*`, `AccountingController`, `ReportController` |
| Banking | `Services/Banking/BankingService`, `BankingController` |
| Notifications | `Services/Notifications/*` (gateway adapter, composer, outbox processor), `finance:outbox` |
| Documents | `Services/Documents/{DocumentStore,PdfRenderer}`, `resources/views/pdf/*` |
| Public links | `Services/PublicAccess/PublicLinkService` |
| Audit | `Services/Audit`, `audit_events` |
| Operations | `Console/Commands/*`, `HealthController`, `OperationsController`, `deploy.sh` |
| Migration | `Services/Migration/MigrationService`, `MigrationController` |

Controllers are thin: validate input, look up tenant-scoped objects (`where('business_id', ctx)`), call a service. Services own state transitions and wrap related writes in DB transactions with row locks (`lockForUpdate`) where concurrent requests could race (issuing, numbering, allocations, signing, recurring runs, reconciliation).

## Request pipeline

`SecurityHeaders` (CSP, nosniff, frame, HSTS, correlation id) → session/CSRF → `central` (verified identity; revalidate ≥ every 5 min; fail closed) → `business` (active membership for the URL's business; else 404) → `perm:*` → controller → service.

Tenant context lives in the URL (`/b/{ulid}/…`), never only in session, so two tabs can work in different businesses. `last_business_id` is only used to pick the landing page.

## Storage

* Database: one dedicated schema and least-privilege user.
* Private files (PDFs, receipts, logos, signatures, CSV uploads): `FINANCE_PRIVATE_STORAGE` (default `/var/lib/mcnutt-finance/private`) outside DocumentRoot; served only through authorized controllers with `Content-Disposition` and `nosniff`.
* Secrets: env file `/etc/mcnutt-finance/finance.env` (root:www-data 0640), symlinked as `.env`. Tax IDs and outbox bodies (which contain bearer links/OTPs) are encrypted with `APP_KEY`.

## Key invariants

* Posted journals balance, belong to one business, are unique per `(business_id, source_key)` and are never edited — corrections are reversing entries.
* Every financial table carries `business_id` with composite FKs `(business_id, x_id) → (business_id, id)` so cross-tenant references are impossible at the DB level.
* Issued invoices, sent contract versions and PDFs are immutable snapshots.
* Public access is via hashed, expiring, revocable tokens exchanged for a session grant.
