# McNutt Finance — implementation specification for GitHub Copilot

Version 1.0 • October 29, 2026 • Target: existing shared LAMP server

## 1. Instructions to the implementing agent

Build the complete application described here, not a mockup or a collection of CRUD screens. Treat this document as the product and engineering acceptance contract. Inspect the repository and deployment conventions first; preserve useful existing infrastructure. Keep implementation progress in docs/IMPLEMENTATION_STATUS.md, with requirements, completed work, test evidence, integration blockers, and remaining work. Do not claim functionality is complete when backed by fixtures or simulated provider responses.

Work through the two phases in section 20. Continue through both phases where dependencies permit. Make routine implementation choices autonomously. Ask only for missing credentials, unavailable integration source, or decisions that materially alter business behavior. Never invent external API capabilities. Build adapters, local tests, and all independent workflows while documenting external blockers. Production startup must distinguish disabled, unconfigured, healthy, and failed integrations. Do not expose development authentication or fake sends in production.

Product intent: replace QuickBooks for small family-owned consulting and service businesses with an excellent multi-business finance, invoicing, and contract application. Match familiar accounting workflows and usability without copying proprietary branding or assets. Make frequent tasks simple while preserving accounting integrity underneath.

Launch must include clients, products/services, customizable invoices and PDFs, email delivery, public invoice pages, check/Zelle payment tracking, recurring billing, service agreements and electronic signatures, expense tracking, bank CSV reconciliation, double-entry bookkeeping, and meaningful P&L reporting. Every business has isolated books and its own brand, settings, members, permissions, customers, payment instructions, and documents.

This is a focused service-business replacement, not full QuickBooks feature parity. Payroll, automated bank feeds, card/ACH processing, inventory costing, tax filing, automated tax jurisdiction determination, and multi-currency are roadmap features. Do not imply that the application provides those capabilities. Currency at launch: USD. Store a currency code on financial records and reject cross-currency allocations.

## 2. Architecture and deployment constraints

Use a modular PHP monolith on Linux + Apache + MySQL/MariaDB. Default to Laravel with Inertia, Vue 3, TypeScript, Tailwind, and an accessible component system if the repository has no framework. Select and pin a maintained framework version compatible with the server's verified PHP version; verify official compatibility documentation before choosing versions. Use Composer and lockfiles. Frontend builds can happen on a workstation or CI; production must serve compiled assets without a Node server. No Docker, Redis, WebSocket service, headless browser, or always-running worker may be required for normal operation.

Use database-backed queues and scheduled jobs executed by a one-minute cron, with bounded worker runs and overlap protection. Pick a maintained PHP PDF renderer compatible with the host, with embedded fonts and no external network dependency at render time. Reuse consistent document view models and snapshots across HTML and PDF; verify representative multi-page documents. Store private files outside Apache DocumentRoot. Apache serves only public/. Use environment-specific configuration, HTTPS, migrations, a least-privilege database user, and persistent storage independent of releases.

Logical modules: Identity; Businesses & Access; Clients; Catalog; Invoicing; Payments; Contracts & Signatures; Recurrence; Expenses; Accounting & Reports; Notifications; Documents; Audit; Operations. Controllers stay thin. Domain services implement transitions; policies authorize every operation; transactions cover related writes. Never depend on browser calculations for totals or permissions.

## 3. Businesses, users, and sharing

A central authenticated user may own or belong to multiple businesses. A business may have multiple authenticated members. Ownership does not imply shared customer or accounting records between businesses. The initial screen is a business chooser when there is no valid last-used context; otherwise open the last authorized business. Always display the active business's name/logo in the header and page title. Provide a searchable switcher with role labels and create-business action when authorized. Context belongs in the URL (/b/{business}/...), not solely a mutable global session. Two browser tabs may safely use different businesses. Invalidate tenant-specific frontend caches on switching.

Business profile: legal name, DBA/display name, entity type, mailing/billing address, phone, public email, website, logo, timezone (default America/New_York), fiscal-year start, default reporting basis, invoice and contract numbering, sender identity, optional tax identifier display (off by default), payment terms, Zelle recipient, check payee/address, permitted methods, branding, reminders, and default contract templates. Mask sensitive profile fields in normal views. Existing issued documents retain their original snapshots when settings change. Changes to payment instructions require fresh central validation and an audit event; prominently warn about pending sends that would use changed instructions.

Members are keyed to verified central principal identifiers, never only mutable email addresses. Support inviting an email address to an existing business; activate membership only after central login and verified identity/email matching or owner approval when the auth system supplies no verified-email claim. Invitation tokens are single-use, expiring, hashed, and bind business plus intended role. No invitation may enroll a user into unrelated businesses. Removing membership immediately blocks access, including downloads and queued actions that depend on that user's authorization. Prevent removal of the last active owner. Ownership transfer requires explicit confirmation and fresh validation.

## 4. McNutt Cloud authentication and permissions

Sources inspected for this specification:
- https://login.mcnutt.cloud/docs/
- https://login.mcnutt.cloud/docs/openapi.yaml

The documented protocol is a custom signed callback and validation flow; do not assume OAuth/OIDC or JWT. Obtain the actual client_helper source from the existing auth deployment/repository before implementing production integration. Documentation names helpers but does not provide their implementation here.

Documented flow:
1. Central login redirects to the registered callback with payload, sig, app_id.
2. handle_sso_callback() verifies HMAC, sets a secure cookie, and removes query parameters.
3. ensure_authenticated() validates/refreshes the identity; ensure_role() supports role gates.
4. GET /api/validate.php accepts token OR api_key plus app_id; successful responses have ok, payload, sig.
5. GET /api/whoami accepts a Bearer personal API key and returns identity, roles, and app assignments.
6. handle_logout_request()/logout_everywhere() integrate central revocation via /api/logout.php.

Config keys documented: login_base, app_id, app_secret, cookie_name, cookie_domain, ttl_sec, refresh_sec, validate_endpoint, logout_endpoint. Register a dedicated application ID and exact callback URL; never reuse sample app secrets or app IDs.

The OpenAPI example inconsistently places roles outside payload, while SessionPayload declares roles inside payload. Resolve the exact deployed signing envelope and serialization with real helper source and sanitized fixtures. Never merge unsigned outer roles into a verified identity. Verify HMAC-SHA256/base64url using the exact provider byte serialization and constant-time comparison; also validate expiration, issuer, expected app context, and allowed principal type. Central identity uid/principal.id is authoritative. Reject magic and system_api principals for internal human sessions unless explicitly supported later. Check application access through a documented authenticated mechanism; do not infer it from user-supplied callback fields.

Use an AuthGateway adapter and framework middleware around the provided helper. Avoid duplicate session implementations with inconsistent revocation. Revalidate within a configurable short window (default 5 minutes) and freshly before membership changes, instruction changes, bulk export, or sensitive accounting actions. Fail closed after that window during auth outages. Record health errors without logging credentials. Cookie guidance shows .mcnutt.cloud; prefer a unique host-only application cookie when compatible with the actual helper, and document any necessary parent-domain exception. Secure, HttpOnly, SameSite; rotate local sessions on login. Validate callback replay protection and browser binding against actual provider support; do not invent a state parameter. If absent, require a documented integration hardening solution before production approval. Redact callback/query tokens in application, Apache, proxy, error, and tracing logs. Trust forwarded IP headers only from configured proxies.

Authorization formula: active central identity + central app access + active business membership + local business permission. Central global admin/user roles do NOT implicitly grant financial access to all businesses. Maintain explicit, audited mappings from verified central roles to application-level capabilities. Optional business-scoped role mappings must explicitly name the business; default off. Local membership roles supply tenant scope. Deny by default. Show effective role and its source. Local revocations take precedence; role mapping must not silently recreate removed membership.

Default roles:
| Capability | Owner | Admin | Accountant | Billing | Viewer |
|---|---|---|---|---|---|
| Business profile/branding | Yes | Yes | Read | Read | Read |
| Ownership, members, permission mappings | Yes | Delegable except ownership | No | No | No |
| Clients/catalog | Yes | Yes | Yes | Yes | Read |
| Invoice create/issue/send/recurrence | Yes | Yes | Yes | Yes | Read |
| Contract author/send/countersign | Yes | Yes | Read | Delegable | Read |
| Record/confirm/reverse payments | Yes | Yes | Yes | Separate grant | Read |
| Expenses/reconciliation/journals | Yes | Yes | Yes | No | Read if granted |
| Financial reports/export | Yes | Yes | Yes | AR only | Separate grant |
| Close periods/reopen/export all | Yes | Delegable | Close only if granted | No | No |

Implement named permissions (e.g. invoices.issue, payments.confirm, contracts.send, reports.pnl.view, books.close) and custom roles, not scattered role-name checks. Admin cannot grant permissions it lacks or alter the owner role. Separate platform operational administration from tenant financial access; any support access must be explicitly granted, time-limited, and audited.

## 5. Internal UX

Deliver a polished responsive finance workspace: calm neutral surfaces, crisp typography, one restrained accent color, consistent spacing, clear status chips, and excellent table readability. Business branding applies to client documents; keep internal navigation consistent. No dense generic admin template.

Navigation: Overview; Clients; Products & Services; Invoices; Recurring Billing; Contracts; Payments; Expenses; Banking; Reports; Settings. Dashboard shows collected revenue, issued revenue, outstanding/overdue AR, expenses, net profit with basis and date range, recurring billing due soon, contracts awaiting signatures, and payment claims requiring review. Every metric links to its filtered source and states its date/basis semantics.

Global new-action menu; searchable/filterable/sortable paginated tables; saved views; keyboard-accessible actions; bulk send with review; customer history; activity timelines; inline validation; useful empty/loading/error states; undo for drafts only; deliberate confirmations for irreversible accounting actions. Invoice editor has live preview, line-item keyboard support, autosaved drafts, and a clear issue/send review step. Contract editor has merge-field validation and recipient preview. Avoid technical integration details on normal client pages.

Target WCAG 2.2 AA: keyboard support, visible focus, screen-reader labels, sufficient contrast, errors linked to fields, reduced motion, and readable mobile screens from 360 px. Distinguish status with text as well as color. Typical indexed list pages should load within 2 seconds on a representative shared-host environment with 10,000 invoices per business; paginate rather than downloading all records.

## 6. Clients and catalog

Clients: individual/company, display/legal names, billing and service addresses, multiple contacts, primary billing contact, separate contract signers, emails/phone, default terms, tax-exemption flag plus evidence, private notes, status, and optional external IDs. One business may have multiple agreements and recurring plans per client. Archive referenced clients; never cascade-delete financial history. Detect likely duplicate email/name combinations without merging automatically. Client detail includes balances, statements, invoices, payments, contracts, recurring plans, and audited interactions.

Catalog: SKU/code, product vs service, name, description, unit, default price, income account, optional cost/COGS mapping, tax category, active status, and optional default recurring cadence. Allow ad hoc invoice lines. Existing documents snapshot names, descriptions, prices, and tax rules; catalog edits affect future drafts only. No stock quantity tracking at launch.

## 7. Invoices and documents

Invoice fields: business/client snapshot, unique business-scoped human number, issue/due/service dates, currency, purchase order/reference, contract version/milestone links, itemized quantities/rates, discounts, tax lines, customer note, terms, allowed payment methods, payment instructions, attachments, and internal notes excluded from public outputs.

Support draft, issued, voided document states; independent delivery and settlement states. Drafts are editable and deletable. Issue assigns number under a locked sequence, freezes the financial snapshot, generates a versioned PDF, and posts the receivable exactly once. Sending an already-issued invoice must not repost it. Issuing before send is allowed and clearly shown. Issued financial details change only by an audited void/reissue or credit/debit adjustment, not in-place mutation. A resend uses the same issued snapshot/PDF. Never reuse invoice numbers. State-based server validation and optimistic locking prevent concurrent edits.

Server decimal arithmetic, never binary floating point. Store posted monetary amounts as integer USD cents; quantities/unit prices and percentages as explicit DECIMAL values. Define rounding as half-up per line, document-level discount allocated proportionally with deterministic residual cents, then exclusive tax on discounted taxable line amounts, then sum. Store calculated line results. V1 supports configured exclusive percentage taxes and exemptions; prohibit unsupported inclusive/compound tax combinations. Show calculation details in preview. Zero totals allowed with no payment CTA; negative invoices prohibited (use credit notes).

Partial payments, overpayments as unapplied credit, credit notes, allocations, refunds, voiding, bounced checks, write-offs, and statements are required. Tax liabilities must remain separate from revenue. Customer statements have opening balance, dated activity, payments/credits, and closing balance. Aging uses as-of outstanding balances and buckets current/1–30/31–60/61–90/90+ days.

Customizable in-app templates: logo, approved font choices, colors, header/footer, contact fields, tax-ID toggle, line-item columns, due-date prominence, terms, customer notes, check/Zelle instructions, and email wording. Provide at least two clean templates and an immediate HTML/PDF preview. Support long descriptions, multi-page tables with repeated headers, stable totals/signature sections, and safe page breaks. Permit safe structured configuration, not arbitrary executable templates, CSS, or HTML. Private attachment downloads require the same authorization as the invoice. Signed PDF and invoice PDFs must render without remote resources.

## 8. Client invoice experience and payment methods

No client account is required. Email provides a prominent View invoice & pay button and a genuine PDF attachment. Public branded mobile page shows business identity, invoice number, amount still due, dates, line items, downloadable PDF, payment instructions, and explicitly linked contract documents. A link grants only that invoice and its expressly linked contract version; never all documents belonging to the customer. No advertising, unrelated navigation, or internal notes.

Support exactly two payment methods at launch:
- Zelle: show configured recipient name and email/phone, exact amount, and invoice-number memo with copy buttons. Show a QR only if the business uploads a verified bank-provided Zelle QR; do not invent a universal Zelle URL/QR protocol. Explain that the client completes payment in their bank's Zelle experience. No collection of bank credentials and no claim that this application processes the transfer.
- Mail check: show check payee, mailing address, memo, and optional downloadable remittance slip. Client may report mailed date/check number; do not request full bank routing/account details.

Client can select I sent payment, method, reported amount/date, optional reference, and optional safe receipt attachment. This creates a payment claim, never a receipt or ledger entry. Show Payment reported — awaiting confirmation. Suppress ordinary overdue reminders only for a configurable short review period; surface stale claims internally. Staff compares real banking/check evidence and confirms, rejects, or requests clarification. Record who confirmed and when. Confirmation is idempotent and requires payments.confirm. Checks may be logged as received/undeposited, deposited, cleared, or returned; choose and disclose the business's receipt-date policy. Deposit/clearance transitions never recognize the same revenue twice. A claim is not an automatic Zelle settlement notification.

Separate payment states: reported, rejected (claims); recorded, deposited, cleared, reversed (actual receipts as applicable). Separate allocation state and invoice paid status from bank reconciliation. Allow manual receipt entry without a client claim. Correct wrong allocations with reversals/reallocations preserving history. Email receipts only after actual receipt confirmation, with amount/method/date and remaining balance. Public page uses truthful wording, including partial payment and overpayment states.

## 9. Email, attachments, and tracking

Required transport: McNutt Cloud notification service; no silent fallback to direct SMTP/SendGrid.

User-supplied contract:
```http
POST https://notifications.mcnutt.cloud/api/send.php
Authorization: Bearer <SERVER_SIDE_TOKEN>
Content-Type: application/json
```
```json
{
  "channel": "email",
  "to": "client@example.com",
  "from": "Business Name <approved-sender@mcnutt.cloud>",
  "subject": "Invoice INV-000123 from Business Name",
  "body": "Rendered message with secure invoice link",
  "provider": "Twilio Email (SendGrid)"
}
```
Optional template_name and data are documented in the supplied example; body is ignored when template_name is provided. Provider is optional. Normalize the URL above to one slash; never send tokens to arbitrary user-configured hosts.

The supplied request DOES NOT document PDF attachments, HTML body semantics, response/message IDs, idempotency, delivery events, webhook signatures, or template behavior beyond the example. Inspect notifications service documentation/source and verify in a sandbox. Put findings and example sanitized payloads in docs/INTEGRATIONS.md. Define a NotificationGateway adapter with message, subject, text/HTML, recipients, attachments, correlation ID, and provider result, then map ONLY verified supported fields.

PDF attachment delivery is mandatory for launch. If absent, implement a backwards-compatible attachment enhancement in the notification service when that repository is available/authorized, or provide a precise companion change contract and mark production invoice emailing blocked. Do not fabricate an attachments field or substitute a download link while calling it a PDF attachment. Proposed service extension must define filename, MIME type, encoding, size limits, sanitization, allowlisted storage retrieval if applicable, and tests. No public permanent file URL just to let the mail service fetch a private PDF.

Use business-approved sender identities; reject CRLF/header injection. Keep tokens server-side. Provide send-test and PDF-preview actions. Email templates: invoice, reminders, receipt, contract invitation, signer reminder, declined/voided agreement, completed contract, and business invitation. Branded HTML plus plain text only after verifying service support.

Queue emails after database commit using an outbox. Record queued, sending, accepted-by-service, delivered (only verified event), bounced, failed, and delivery-unknown independently. Persist attempts, sanitized error, message ID when provided, and exact document/version reference. A 200 response is not automatically delivered. Retry transient failures with backoff/jitter and bounded attempts; permanent errors require correction. If timeout occurs after possible acceptance and provider lacks idempotency/query support, mark ambiguous and require explicit resend review; do not promise exactly-once email.

Tracking: invoice issued, email queued/accepted/delivered if observable, pixel observed, link accessed, invoice viewed, PDF downloaded, claim submitted, receipt confirmed, contract viewed/signed/completed. Opens are approximate due to image proxies/privacy/scanners; label as Open detected, not proof of reading. A GET/pixel/scanner must never sign, pay, consume a one-time token, or transition invoice financial state. Separate possible automated link hits from interactive page activity when measurable, without claiming perfect detection. Public client access must function with tracking disabled. No third-party analytics on document pages. Signed webhooks only if actually supported; reject replay, cross-business event mismatch, and forged signatures.

## 10. Public links and document security

Use cryptographically random >=256-bit opaque tokens; store token hashes, not raw bearer tokens, with business, resource/version, purpose, allowed actions, intended recipient where relevant, expiration, revocation, and usage metadata. Never expose sequential IDs as authorization. Default invoice links expire after 90 days and contract invitations after 30 days; owners can configure within safe bounds, revoke, or reissue. A valid link may remain useful after payment to retrieve the receipt until expiry. Renewal requires staff resend or an email-to-original-contact flow that does not reveal document existence.

Exchange URL tokens for a narrowly scoped secure client cookie and redirect to a clean URL; prevent token capture in logs before that first redirect. Use no-referrer, no-store, noindex, restrictive CSP, HTTPS, rate limiting, safe download filenames, and no external assets. Signer tokens are recipient-specific and distinct from invoice-view links. Public invoice viewers cannot sign a linked contract using invoice access alone. Contract-signing pages use CSRF/nonces and explicit POST confirmations. Do not store raw tokens in plaintext outbox logs: use encrypted delivery material, limited retention, and secure purge after the send lifecycle. Background jobs must recheck document state and link revocation before sending.

## 11. Contracts, service agreements, and electronic signatures

Support reusable structured templates and uploaded PDFs. Template merge fields include business/client profiles, scope, term dates, rate tables, milestones, recurring fees, payment terms, and signature blocks. Validate required fields and preview before send. Store immutable contract versions, source files, rendered PDF, SHA-256 digest, template version, and linked client/business snapshots. An agreement is distinct from its versions, recipients, amendments, invoices, and billing schedules.

Lifecycle: draft → prepared → sent → partially_signed → completed; additional declined, expired, voided states. Track effective date and term/termination separately from signing completion. Changes after send require voiding the envelope and creating a new version; signed originals remain accessible. Amendments are new versioned documents referencing the original; they do not rewrite it or retroactively change invoices. Support client signer plus optional business countersigner, sequential or parallel order, and one or more client signers. Recipient roles and required-signature completion criteria are explicit. Owners may upload externally signed agreements with recorded provenance; label externally signed and never fabricate an in-app signature trail.

Client signing experience: review the full immutable document, download it, explicitly consent to electronic records/signature, enter legal name/title and authority acknowledgement, type or draw signature, then confirm signing with a clear action. Default email verification via one-time code to the designated signer email; hash codes, expire after 10 minutes, maximum 5 attempts, limit resends, and restart verification after email changes. The signing link itself is not sufficient to prove identity. Record method and limitations without suggesting verified legal identity. No login required.

Bind each signature transaction to exact document version/hash; lock state for concurrent signing, reject duplicate/expired/voided submissions, and freeze the final artifact once all required parties sign. Record recipient, typed name/title, signature representation hash, consent text/version, verification result, UTC timestamp, IP from trusted proxy configuration, user agent, document hash, and event sequence. Produce completed PDF plus audit certificate listing document/version hashes and signature events. Preserve both original unsigned document hash and final signed artifact hash. Application-level hashes are evidence of integrity, not a certificate-based digital signature or trusted timestamp. Use append-only audit permissions and encrypted backups; disclose system-administrator tampering limitations.

Completed agreements and certificate go to all designated recipients and remain accessible through scoped links. Downloading must not count as signing. Contract detail shows signing status, committed value, invoiced/collected/remaining amounts with defined calculations, next billing date, billing plan, amendments, and activity. Restrict clients to public terms, not internal margin or notes. Store electronic consent and provide a downloadable copy and a staff-assisted non-electronic alternative. Do not claim blanket legal enforceability; document workflow and configurable retention for review by business counsel without making legal research a blocker to implementation.

## 12. Link contracts to invoicing and recurring billing

Each invoice can reference one agreement and immutable version, with individual lines optionally mapped to agreement milestones/rate items. Many invoices can reference one agreement. Public invoice displays that exact version and completed signature packet where applicable. Draft/prepared/private documents cannot be exposed by linking an invoice. Public links to later amendments require explicit authorization; no automatic access to every agreement version.

Billing plans support one-time deposits, milestone billing, fixed recurring retainers, and manual variable additions. Default agreement-linked automation requires completed signatures plus effective date; an explicit owner override with reason may allow billing against an externally executed agreement. Contract termination/expiry pauses future billing by default, with explicit permitted final charges. Changing an agreement never silently changes an active schedule. Show and audit proposed schedule changes and affected future periods. Contract signature alone never recognizes revenue. Track unbilled contract value separately from accounting revenue; optional contract-value caps warn before overbilling and can block according to settings.

Recurring schedules: cadence weekly/monthly/quarterly/annual or every N supported units, start/end, next local run date/time, timezone, day-of-month/month-end behavior, terms, recurring line snapshots or explicit future catalog-price policy, email recipients, auto-issue/auto-send toggle, payment methods, linked contract version, reminder rules, and draft-review option. Default month-end rule clamps to last day and restores the original anchor in following months. Handle leap years and DST deterministically.

Use unique (business_id, schedule_id, billing_period_key) to prevent duplicate invoice generation. Lock schedule runs, generate invoice/outbox atomically, and advance next-run only after durable success. Cron outages produce a catch-up review queue with at most one automatically sent overdue period per schedule; older missed periods require reviewed generation rather than an email storm. Retry failed sends on the existing invoice; never generate a replacement invoice for delivery retries. Pause/resume/edit/cancel with explicit effective period; no retroactive mutation. Auto-send means requesting payment by email, never automatically debiting Zelle or a bank account. Show preview of the next three occurrences.

## 13. Finance and accounting foundation

Use a small real double-entry ledger rather than deriving P&L from invoice statuses. Seed each business with a usable chart: bank, cash/undeposited funds, AR, customer advances, sales tax payable, owner equity/contributions/draws, retained earnings, service/product income, refunds/discounts, common operating expenses, COGS, and opening balance equity. Permit authorized account customization; referenced accounts are archived, not deleted. Every posted journal balances in cents, belongs to one business, and links to its source/version. Post atomically, enforce source-event idempotency, and prohibit edits/deletions after posting. Corrections use reversing/adjusting entries with actor/reason. A database transaction and locking prevent concurrent over-allocation.

Baseline entries:
| Event | Debit | Credit |
|---|---|---|
| Issued invoice | AR gross | Revenue net + tax payable |
| Confirmed invoice payment | Bank or undeposited funds | AR allocated amount |
| Unapplied receipt/customer deposit | Bank/undeposited funds | Customer advances |
| Apply advance | Customer advances | AR |
| Move check to bank | Bank | Undeposited funds |
| Paid expense | Expense/COGS or asset | Bank or owner contribution as appropriate |
| Unpaid vendor bill | Expense/asset | AP |
| Pay vendor bill | AP | Bank |
| Credit note | Revenue contra/tax reversal as applicable | AR and/or customer credit liability |
| Owner draw | Owner equity/draws | Bank |

Handle returned payments by reversing their receipt/allocation consistently; tax, credit note, bad-debt, refund, and deposit entries must use documented accounting policies. Invoice a prepaid service only once; applying a deposit must not recognize income twice. Keep owner contributions, transfers, loan proceeds/principal, tax collections, and draws out of P&L. Include manual journals for accountant corrections, restricted and audited. Implement basic period closing: prevent backdated postings in closed periods; privileged reopen requires reason and audit. Reports remain reproducible with as-of cutoffs.

Accrual P&L uses posted revenue/expense accounting dates. Do not implement cash P&L merely as cash movements or paid invoice totals. Implement a tested cash-basis reporting projection: recognize eligible income and tax components proportionally to confirmed applied receipts, expenses upon actual payment, unapplied operating advances according to documented policy, and reversals/refunds in their proper period. Support opening AR/AP, credit notes, partial receipts, overpayments, and payments spanning reporting periods. Maintain one canonical ledger plus traceable basis adjustments/projection, not two manually divergent books. Clearly label cash reports as management reporting and document policy limitations; do not call them tax returns. Balance sheet and trial balance at launch are accrual; cash P&L is a separate labeled view. Do not show a cash balance sheet unless implemented and reconciled.

At launch default service revenue accrual occurs on invoice accounting date; include service-period metadata and accountant manual deferral adjustments for prepaid/unearned work. Do not claim full automated revenue recognition. All reports show basis, business, currency, period, generated timestamp, and drilldown source lines. Shared-service-user access never creates consolidated accounting by default; optional cross-business overview aggregates only businesses the user can report on and labels it as management aggregation.

## 14. Expenses, banking, and migration

Expenses: vendor, date, account/category, description, amount, business currency, paid/unpaid, payment account/method, optional client/contract, receipt uploads, billable flag, split categories, tax treatment, and private notes. Prevent receipt upload from posting an expense twice. Mark potentially duplicate vendor/date/amount combinations. Billable expenses may become invoice lines through an explicit action, preserving source links and avoiding duplicate expense recognition. Support basic vendor bills/AP and their payments so unpaid costs are visible. No OCR dependency; extraction is roadmap.

Banking: manual accounts, opening balances, CSV imports with column/date mapping, preview, validation, per-row source identity and duplicate detection, staged transactions, matching to existing payments/expenses/transfers, and reconciliation to statement opening/closing balance. Imported bank rows are staging records and do not automatically create duplicate ledger entries. Allow match, split, exclude, create new posting, and undo match with audit. Model transfers as two account legs of one event. Never match across businesses or currencies. Statement import and reconciliation are manual; no automated Zelle settlement inference. Include undoable import batches for unposted rows.

Migration: CSV templates for clients, catalog, opening AR/AP and balances, historical expenses, and payment allocations. Import wizard previews mapping/errors, supports dry run, duplicate keys, batch tracking, and preserves original identifiers. Keep imported historical invoice totals and receipts consistent with opening journals: choose either transactional history posting or opening-summary migration per cutoff and prevent double counting. Provide a documented QuickBooks CSV mapping guide using actual exported files when available; do not promise a proprietary one-click QuickBooks importer.

## 15. Reports

Required: cash and accrual P&L with monthly/comparative views; accrual balance sheet; trial balance; general ledger; AR/AP aging; invoice/collection summaries; expenses by category/vendor; sales by client/product; sales-tax liability and collected-tax supporting schedule; recurring billing forecast; contract committed/invoiced/collected values. Revenue forecast and contract value are clearly separate from recognized revenue. Provide CSV and print/PDF exports with permission checks. Dates use business local boundaries converted consistently to UTC; accounting dates are DATE fields distinct from event timestamps. Drilldowns reconcile exactly to report totals and exclude drafts, void effects net of reversals, and payment claims.

## 16. Data model and integrity

Use migrations with explicit constraints and indexes. UUID/ULID public identifiers do not replace authorization. Financial tables carry business_id and composite tenant foreign keys/unique constraints wherever supported to prevent cross-tenant references. Do not rely only on ORM global scopes. Required aggregates/tables:
- users (central principal type/id unique), businesses, memberships, roles, permissions, role_permissions, membership_roles, central_role_mappings, invitations;
- clients, contacts, addresses; products_services; vendors;
- invoice_sequences, invoices, invoice_lines, invoice_tax_lines, invoice_document_versions, credit_notes/lines;
- payment_claims, payments, payment_allocations, refunds/reversals, customer_credit_entries;
- contracts, contract_templates, contract_versions, contract_recipients, signature_envelopes, signature_events, electronic_consents, amendments;
- recurring_schedules, recurring_line_items, schedule_runs, contract_billing_milestones;
- chart_accounts, journal_entries, journal_lines, accounting_periods, cash_basis_projection_events;
- expenses, expense_lines, vendor_bills, vendor_payments, bank_accounts, import_batches, bank_transactions, reconciliation_sessions/matches;
- private_documents, public_tokens, email_outbox, email_attempts, provider_events, document_access_events, jobs, audit_events, idempotency_keys.

Specify table-level columns and domain relationships in docs/DATA_MODEL.md before migration implementation. Important keys: unique business invoice/contract number; unique recurring period; unique posting source event; unique provider event ID when available; unique central principal; unique active membership; unique scoped idempotency key. Persist input snapshots, status/version, accounting dates, UTC timestamps, actor IDs, and source IDs. Index business/status/due_date, business/client, business/account/date, queue available_at, and recurrence next_run_at. Avoid polymorphic references that cannot be validated tenant-safely; use explicit relationships or domain validation plus constraints.

Payment allocation cannot exceed available receipt/credit or invoice outstanding balance; an overpayment remains unapplied. Invoice outstanding = issued total minus valid credit allocations minus confirmed receipt allocations, adjusted by reversals; recomputable, not an independently editable field. Enforce balance and cents precision at posting boundary. Attachments have MIME/size/hash, safe generated filenames, immutable version links, and tenant scope. Data retention must preserve issued/signed/posted records; archived business records remain exportable. Do not hard-delete legal/accounting history through ordinary UI.

## 17. Routes and APIs

Use framework-native pages/forms with JSON endpoints where useful. Publish a route/permission inventory and OpenAPI for JSON interfaces. Representative protected routes:
- /auth/login, /auth/callback, /auth/logout
- /businesses and /b/{business}/switch (switch validates membership)
- /b/{business}/clients, /catalog, /vendors
- /b/{business}/invoices; /{invoice}/issue, /send, /void, /credit, /pdf
- /b/{business}/payments; /claims/{claim}/confirm or /reject; /{payment}/reverse
- /b/{business}/contracts; /{contract}/versions, /send, /void, /amend, /pdf
- /b/{business}/recurring; /{schedule}/preview, /pause, /resume
- /b/{business}/expenses, /bills, /bank-imports, /reconciliations
- /b/{business}/reports/{report}, /journals, /periods
- /b/{business}/settings/profile, /branding, /payments, /email, /members, /roles

Public routes separated from internal auth: /i/{token} exchange; scoped invoice view/PDF/payment-report; /c/{token} exchange; signer verification/consent/sign/decline/download. Expose only explicitly scoped linked artifacts. Optional provider webhook routes exist only after provider verification. All state transitions use POST/PATCH, CSRF protection for cookie sessions, schema validation, permission checks, explicit idempotency keys where relevant, and consistent error responses. Return neutral 404/expired-link pages without disclosing other records. Rate-limit verification, token lookup, payment claims, resend actions, and exports.

## 18. Operational robustness and security

Protect against IDOR across every route, export, nested relation, background job, and file. Business ID comes from authorized context and is checked against every referenced object. Audit background actor plus business; recheck automation authority and business active state at execution. Use dedicated schedule authority rather than accidentally coupling all recurring billing to the creator's current membership. Suspending a business cancels future execution; paused/cancelled contracts/schedules are rechecked immediately before issue/send.

CSRF, XSS protection, prepared queries, safe rich text sanitization, upload MIME validation/limits, no SVG/script uploads without trusted sanitization, no executable uploads, restricted outbound integration hosts, request limits, secret redaction, and environment separation. Disable PDF renderer remote fetch and local arbitrary-file access. Never embed client bank credentials, password storage, or a second local password login. Encrypt integration secrets and any stored raw delivery tokens with a deployment key outside the database. Back up that key separately so restore works.

Audit actor, business, action, object/version, UTC time, correlation ID, sanitized before/after diff, and reason on sensitive changes. No credentials or full bearer URLs. Audit accounting posts/reversals, member changes, payment instructions, public-token revocation, document edits, signatures, sends, exports, and close/reopen. Use transactionally persisted events and restrictive database permissions; document that audit is not cryptographically invulnerable to the host administrator.

Provide an owner-visible automation monitor: next run, last success, failures, jobs needing retry, ambiguous sends, auth/email health, storage usage, cron heartbeat, and backup freshness. Logs with correlation IDs; no public stack traces. Health endpoint must reveal no secrets. Configure encrypted daily DB/files backups to a separate destination with default 30-day operational retention, plus configurable longer financial-document retention. Provide tested restore instructions and sample restore verification; target RPO 24h, RTO 4h subject to actual host capacity. Never claim these targets met before a restore drill.

## 19. Acceptance scenarios and test gates

Write meaningful unit/integration tests for financial logic, tenant/security boundaries, and asynchronous behavior; browser tests for critical workflows. Tests must run against MySQL/MariaDB-compatible constraints, not only SQLite. Use fake gateways for deterministic tests and a separate documented sandbox test for real integration behavior. Minimum release gates:
1. One user has two businesses, another only one. Switching/two tabs never leaks clients, reports, downloads, links, or files; altered business/object IDs fail everywhere.
2. Valid central login works; bad HMAC, expired identity, unsigned role changes, wrong app, revoked session/membership, and unsupported principals fail. Auth outage follows bounded revalidation policy.
3. Every role and custom permission is enforced server-side; last owner cannot be removed; global central admin does not gain tenant books.
4. Create client/catalog/invoice, preview customized PDF, issue once, send via real notification adapter with actual PDF attachment. Changing catalog/branding afterward leaves issued PDF unchanged.
5. Client opens without login, sees only authorized invoice/linked version, reports Zelle/check payment; no paid status, receipt email, or P&L change occurs until confirmation.
6. Confirm $400 of a $1,000 invoice; remaining is $600. Duplicate confirmation/posting does nothing twice. Credit notes, overpayments, returned checks, refunds, and reversals reconcile.
7. An invoice of $1,000 net plus $100 tax paid $550 recognizes $500 income/$50 tax components in cash projection under documented policy, and $1,000 accrual revenue at issue. A $200 paid expense yields $300 cash and $800 accrual operating profit in that period, assuming same-period issue and no other activity.
8. Deposits, owner contributions/draws, loan principal, and bank transfers do not create duplicate revenue/expenses; prior-period invoices and partial payments report correctly across cutoffs. Balanced ledger and AR/AP control accounts reconcile.
9. Contract is immutable after send; signer verification, explicit consent, signatures/countersignatures, final PDF, and audit certificate work. Concurrent/duplicate submissions create one signature. Voided/expired invitations cannot sign; invoice access cannot sign or view unrelated contracts.
10. Completed agreement unlocks permitted recurring billing. Two simultaneous cron invocations produce one invoice per period. Month-end/DST, leap year, pause, termination, catch-up, creator removal, and delivery failures behave as specified.
11. Provider acceptance is distinct from delivery; spoofed/duplicate callbacks are rejected if callbacks exist. Pixel/scanner GETs do not sign, consume verification, or settle invoices. Ambiguous sends require review.
12. CSV import preview/dedupe/match avoids duplicate ledger activity; reconciliation matches statement balances. Migration cutoff prevents opening-balance/history double counting.
13. Cash/accrual P&L drilldowns match totals; balance sheet balances; trial balance totals agree; closed periods reject unauthorized backdating.
14. Mobile client payment/signature flows and internal invoice editor pass keyboard/accessibility checks. Multi-page PDF templates have no clipped text, orphaned totals, or missing fonts.
15. A clean host can install from documented steps; production build needs no Node daemon. Cron processes bounded queues; backups restore DB/files/keys and reconcile test balances.

Include numeric golden fixtures for rounding, mixed taxable/non-taxable lines, allocated discounts, partial receipts, credit taxes, refunds, deposits, and as-of aging. Document unresolved integration tests explicitly; never mark mock success as production verification.

## 20. Implementation phases and deliverables

### Phase 1 — operational invoicing and books

Inspect repository/host compatibility; implement auth adapter and tenant boundaries; profiles/members/permissions; design system; clients/catalog; invoice editor/customization/PDF; scoped public invoice page; real email/attachment adapter; manual Zelle/check claims and confirmation; expenses/vendor bills; double-entry posting; cash/accrual P&L and foundational reports; CSV bank import/reconciliation; audit and deployment skeleton. Phase 1 must be usable end to end with properly configured integrations, without fake accounting or mocked payments. Implement extension points/schema for contracts and recurrence to avoid rebuilding core entities.

### Phase 2 — agreements and automation

Implement contract templates/uploads/versioning, recipient verification and signatures, signed packet/audit certificate, agreement-linked invoices, milestones and recurring billing, reminders, reliable cron/outbox processing, full dashboards/report exports, migration tools, backup/restore validation, accessibility/performance polish, and all acceptance gates. Complete this phase before calling the overall product finished.

Commit deliverables into the application repository:
- Application source, migrations, seed chart of accounts, tests, dependency lockfiles, compiled build procedure, and sanitized .env.example.
- README.md with development and production setup.
- docs/ARCHITECTURE.md, DATA_MODEL.md, AUTH_INTEGRATION.md, INTEGRATIONS.md, PERMISSIONS.md, ACCOUNTING_POLICIES.md, PUBLIC_LINK_SECURITY.md, SIGNATURE_EVIDENCE.md, CRON_AND_QUEUES.md, DEPLOYMENT.md, BACKUP_RESTORE.md, MIGRATION.md, ACCEPTANCE_TESTS.md, IMPLEMENTATION_STATUS.md, ROADMAP.md.
- Example Apache vhost and safe cron, deployment preflight, environment validation, and a single documented application setup command after dependencies/configuration exist. Do not run destructive migration reset or overwrite other shared applications.
- Demo seed data only through an explicitly nonproduction command: two businesses, shared and restricted members, clients, partial/overdue/paid invoices, contracts awaiting/completed signatures, and recurring plans.

Configuration inventory: APP_URL, APP_ENV, APP_KEY, database credentials, AUTH_LOGIN_BASE, AUTH_APP_ID, AUTH_APP_SECRET, auth helper/session options, NOTIFICATIONS_BASE_URL (fixed allowlisted origin), NOTIFICATIONS_API_TOKEN, default approved sender/provider, private storage path, timezone defaults, cron/queue limits, public-link TTLs, signer-code limits, upload limits, backup destination/keys. Document actual supported adapter fields rather than invented provider configuration.

### Definition of done

All requested launch features are implemented with a coherent UX, tenant-safe authorization, traceable accounting, functioning real email with PDF attachments, scoped client access, complete contract signing, recurring generation/sending, passing critical tests, and reproducible LAMP deployment. Secrets and app registration may require operator setup; identify those plainly. Any unresolved auth protocol ambiguity or missing attachment capability is a named production blocker, not a reason to silently weaken requirements. The final agent handoff states what works, validation performed, exact remaining configuration/blockers, and how to deploy and operate it.

## 21. Explicit roadmap

Later only: automated bank feeds, verified bank/Zelle settlement adapters where available, card/ACH payment providers, payroll, automated sales-tax determination/remittance, 1099 preparation, inventory, multi-currency, advanced revenue recognition, time tracking, proposal/estimate conversion, OCR expense capture, advanced budgets, accountant collaboration across firms, and qualified/certificate-based signature providers. Preserve adapter boundaries without building placeholder production buttons.
