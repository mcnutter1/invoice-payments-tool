# Route & permission inventory

Generated from `php artisan route:list` (re-generate after route changes). All `/b/{business}/…` routes run `central` (verified McNutt Cloud session, revalidated every 5 minutes, fail-closed) and `business` (active membership in the business in the URL; non-members get a neutral 404). `perm:x` is enforced server-side; `a\|b` means either permission. `central:fresh` forces a fresh revalidation with McNutt Cloud (≤60 s) before sensitive actions. Every object lookup inside a controller is additionally scoped by `business_id` from the authorized context.

JSON interfaces: only `/health` and the invoice-editor autosave response (`X-Autosave: 1`) return JSON; see [openapi.yaml](openapi.yaml).

| Method | URI | Middleware (auth / permission) |
|---|---|---|
| GET | / | central |
| GET | /auth/callback | throttle:auth |
| GET/POST | /auth/dev-login | throttle:auth (404 unless AUTH_DRIVER=dev and not production) |
| GET | /auth/login | throttle:auth |
| POST | /auth/logout | throttle:auth |
| GET | /b/{business} | central, business |
| GET | /b/{business}/accounts | central, business, perm:journals.view |
| POST | /b/{business}/accounts | central, business, perm:journals.manage |
| POST | /b/{business}/accounts/{id} | central, business, perm:journals.manage |
| POST | /b/{business}/allocations/{id}/unapply | central, business, perm:payments.reverse, central:fresh |
| GET | /b/{business}/audit | central, business, perm:audit.view |
| GET | /b/{business}/automation | central, business, perm:automation.view |
| POST | /b/{business}/bank-transactions/{id}/categorize | central, business, perm:banking.manage |
| POST | /b/{business}/bank-transactions/{id}/exclude | central, business, perm:banking.manage |
| POST | /b/{business}/bank-transactions/{id}/match | central, business, perm:banking.manage |
| POST | /b/{business}/bank-transactions/{id}/undo | central, business, perm:banking.manage |
| GET | /b/{business}/banking | central, business, perm:banking.view |
| POST | /b/{business}/banking | central, business, perm:banking.manage |
| GET | /b/{business}/banking/{id} | central, business, perm:banking.view |
| POST | /b/{business}/banking/{id}/import | central, business, perm:banking.manage |
| GET/POST | /b/{business}/banking/{id}/import/{doc} | central, business, perm:banking.manage |
| POST | /b/{business}/banking/{id}/reconcile | central, business, perm:banking.manage |
| GET | /b/{business}/catalog | central, business, perm:clients.view\|catalog.manage |
| POST | /b/{business}/catalog, /catalog/{id}, /tax-rates, /tax-rates/{id}, /vendors, /vendors/{id} | central, business, perm:catalog.manage |
| GET | /b/{business}/claims/{id}, /claims/{id}/attachment | central, business, perm:payments.view |
| POST | /b/{business}/claims/{id}/confirm | central, business, perm:payments.confirm, central:fresh |
| POST | /b/{business}/claims/{id}/reject, /clarify | central, business, perm:payments.confirm |
| GET | /b/{business}/clients, /clients/{id}, /clients/{id}/statement | central, business, perm:clients.view |
| GET/POST | /b/{business}/clients/new, /clients, /clients/{id}, /clients/{id}/edit, /archive, /contacts… | central, business, perm:clients.manage |
| GET | /b/{business}/contracts, /contracts/{id}, /contracts/{id}/versions/{version}/{kind}, /contract-templates | central, business, perm:contracts.view |
| GET/POST | /b/{business}/contracts/new, /contracts, /contracts/{id}, /edit, /prepare, /new-version, /amend, /external, /terminate, /contract-templates… | central, business, perm:contracts.manage |
| POST | /b/{business}/contracts/{id}/billing-override | central, business, perm:contracts.manage, central:fresh |
| POST | /b/{business}/contracts/{id}/send, /void, /contract-recipients/{id}/remind | central, business, perm:contracts.send |
| GET | /b/{business}/contracts/{id}/countersign | central, business, perm:contracts.countersign |
| POST | /b/{business}/contracts/{id}/countersign | central, business, perm:contracts.countersign, central:fresh |
| POST | /b/{business}/contracts/{id}/milestones/{milestone}/invoice | central, business, perm:invoices.manage |
| GET | /b/{business}/expenses, /expenses/{id}, /expenses/{id}/receipt | central, business, perm:expenses.view |
| GET/POST | /b/{business}/expenses/new, /expenses, /expenses/{id}/pay, /void, /receipt | central, business, perm:expenses.manage |
| POST | /b/{business}/expenses/{id}/bill-client | central, business, perm:invoices.manage |
| POST | /b/{business}/imports/{id}/undo | central, business, perm:banking.manage |
| GET | /b/{business}/invoices, /invoices/{id}, /invoices/{id}/pdf, /invoices/{id}/documents/{version} | central, business, perm:invoices.view |
| GET/POST | /b/{business}/invoices/new, /invoices, /invoices/{id}, /edit, /delete, /duplicate | central, business, perm:invoices.manage |
| POST | /b/{business}/invoices/{id}/issue | central, business, perm:invoices.issue |
| GET/POST | /b/{business}/invoices/{id}/send, /revoke-links, /invoices/bulk-send, /outbox/{id}/resend | central, business, perm:invoices.send (+ throttle:sensitive on sends) |
| GET/POST | /b/{business}/invoices/{id}/void, /credit, /write-off | central, business, perm:invoices.void |
| GET | /b/{business}/journals, /journals/{id}, /periods | central, business, perm:journals.view |
| GET | /b/{business}/journals/new | central, business, perm:journals.manage |
| POST | /b/{business}/journals, /journals/{id}/reverse | central, business, perm:journals.manage, central:fresh |
| GET/POST | /b/{business}/migration… | central, business, perm:journals.manage AND perm:clients.manage |
| GET/POST | /b/{business}/movements/new, /movements | central, business, perm:banking.manage |
| GET | /b/{business}/payments, /payments/{id} | central, business, perm:payments.view |
| GET/POST | /b/{business}/payments/new, /payments, /payments/{id}/apply, /deposit, /clear | central, business, perm:payments.record |
| POST | /b/{business}/payments/{id}/refund, /reverse | central, business, perm:payments.reverse, central:fresh |
| POST | /b/{business}/periods/close | central, business, perm:books.close, central:fresh |
| POST | /b/{business}/periods/reopen | central, business, perm:books.reopen, central:fresh |
| GET | /b/{business}/reconciliations/{id} | central, business, perm:banking.view |
| POST | /b/{business}/reconciliations/{id}, /cancel, /undo | central, business, perm:banking.manage |
| GET | /b/{business}/recurring, /recurring/{id} | central, business, perm:recurring.view |
| GET/POST | /b/{business}/recurring/new, /recurring, /recurring/{id}, /edit, /status, /schedule-runs/{id}/generate, /skip | central, business, perm:recurring.manage |
| GET | /b/{business}/reports, /reports/{report} | central, business, perm:reports.ar.view\|reports.pnl.view (per-report permission checked in controller; CSV/PDF needs reports.export) |
| GET | /b/{business}/search, /switch | central, business (results filtered by permission) |
| GET | /b/{business}/settings, /settings/logo | central, business, perm:business.view |
| POST | /b/{business}/settings/profile, /branding, /email, /email/test; GET /settings/preview-pdf | central, business, perm:business.manage |
| POST | /b/{business}/settings/payments | central, business, perm:business.manage, central:fresh |
| GET | /b/{business}/settings/members | central, business, perm:members.manage |
| POST | /b/{business}/settings/members/invite, /{id}/approve, /{id}/remove, /{id}/roles | central, business, perm:members.manage, central:fresh |
| POST | /b/{business}/settings/invitations/{id}/revoke | central, business, perm:members.manage |
| GET/POST | /b/{business}/settings/roles, /roles/{id} | central, business, perm:roles.manage (+ central:fresh on POST) |
| POST | /b/{business}/settings/ownership | central, business, perm:ownership.transfer, central:fresh |
| GET/POST | /businesses, /businesses/new | central (+ businesses.create capability) |
| GET/POST | /invite/{token} | central |
| GET | /platform | central (FINANCE_PLATFORM_ADMINS or platform.operations mapping) |
| POST | /platform/role-mappings, /{id}/delete | central, central:fresh (FINANCE_PLATFORM_ADMINS only) |
| GET | /health | none (no secrets) |
| GET | /i/{token} | public.doc, throttle:public, throttle:public-token |
| GET | /i/v/{ulid}, /pdf, /remittance, /agreement, /agreement/certificate | public.doc, throttle:public (+ session grant from /i/{token}) |
| POST | /i/v/{ulid}/report-payment | public.doc, throttle:public-write (+ grant with report_payment) |
| GET | /c/{token} | public.doc, throttle:public, throttle:public-token |
| GET | /c/r/{recipient}, /document, /signed, /certificate, /consent | public.doc, throttle:public (+ recipient-specific grant) |
| POST | /c/r/{recipient}/code, /verify, /sign, /decline | public.doc, throttle:public-write (+ contract_sign grant with sign action, CSRF) |
