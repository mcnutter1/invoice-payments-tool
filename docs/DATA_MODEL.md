# Data model

Migrations: `database/migrations/2026_10_29_0001xx…0005xx`. Money is stored as **integer cents** (`*_cents`, BIGINT); quantities/prices/rates are `DECIMAL(15,4)` / `DECIMAL(9,4)`. Accounting dates are `DATE`; event times are UTC timestamps. Every tenant table has `business_id`; child tables use composite FKs `(business_id, parent_id) → parent(business_id, id)` backed by `UNIQUE(business_id, id)` on the parent.

## Identity & access
| Table | Purpose / key columns |
|---|---|
| users | `central_principal_type`+`central_principal_id` **unique** (authoritative central identity), email/name (mutable, informational), `central_roles` (verified, from signed payload), `last_business_id` |
| sessions | Laravel DB sessions (encrypted payload) |
| auth_callback_nonces | sha256(sig) of redeemed SSO callbacks (replay protection) |
| businesses | profile, branding (JSON), payment instructions, numbering prefixes, link TTLs, `books_closed_through`, `status` |
| permissions | catalog (`key` unique) |
| roles / role_permissions | per-business roles (5 system + custom) |
| memberships | `UNIQUE(business_id,user_id)`, `status` active/pending_approval/removed |
| membership_roles | composite FKs to membership and role in the same business |
| central_role_mappings | central role → platform capability (`businesses.create`, `platform.operations`); never tenant books |
| invitations | email, role, `token_hash` unique, expiry, single use |

## Clients, catalog, vendors
`clients` (billing/service address, terms, tax_exempt, status, external_id) · `contacts` (primary billing, signer flags) · `tax_rates` · `products_services` (income/COGS accounts, tax rate) · `vendors`.

## Ledger
| Table | Notes |
|---|---|
| chart_accounts | `UNIQUE(business_id,code)`, `UNIQUE(business_id,system_key)`; type asset/liability/equity/income/expense; archived not deleted |
| journal_entries | `UNIQUE(business_id, source_key)` = posting idempotency; `reversal_of_id`; `kind` auto/manual/opening |
| journal_lines | debit/credit cents, account, optional client/vendor/invoice, denormalized `entry_date`, `reconciliation_id`; index (business, account, date) |
| cash_basis_projection_events | signed natural amounts per account for the cash-basis P&L; `UNIQUE(business_id, source_key, account_id)` |
| accounting_periods | close/reopen history (actor, reason) |

## Invoicing & payments
| Table | Notes |
|---|---|
| invoice_sequences | per business & kind, row-locked when numbering |
| invoices | `UNIQUE(business_id,number)`; status draft/issued/void; `delivery_status`; `settlement_status`; totals; cached paid/credited/written-off/balance (recomputed from allocations, never edited directly); JSON snapshots of client, business, instructions, template; `lock_version`; index (business,status,due_date), (business,client) |
| invoice_lines / invoice_tax_lines | stored calculation results per line and per tax rate |
| invoice_document_versions | immutable PDF versions (document + sha256) |
| credit_notes / credit_note_lines | `kind` credit or write_off |
| payment_claims | client reports; never ledger entries |
| payments | actual receipts; status recorded/deposited/cleared/reversed; `UNIQUE(business_id, claim_id)` makes claim confirmation idempotent |
| payment_allocations | payment **or** credit note → invoice; status active/reversed |
| refunds | refunds of unapplied customer credit |

Customer credit (spec "customer_credit_entries") is derived from the Customer Advances account lines per client plus `payments.unappliedCents()`; no separate mutable balance.

## Contracts
`contract_templates` (versioned) · `contracts` (agreement: number, client, status, effective/term dates, committed value, cap, signing order, external provenance, billing override) · `contract_versions` (immutable once sent: body snapshot, rendered/signed/certificate documents and SHA-256s; `amends_version_id` for amendments — the "signature envelope" is the sent version) · `contract_recipients` (role, order, OTP hash/expiry/attempts, verification, signature data) · `electronic_consents` · `signature_events` (`UNIQUE(contract_version_id, seq)`) · `contract_billing_milestones`.

## Recurrence
`recurring_schedules` (cadence, interval, anchor, next_run_date, automation flags, agreement link) · `recurring_line_items` · `schedule_runs` **`UNIQUE(business_id, recurring_schedule_id, billing_period_key)`**.

## Expenses & banking
`expenses` (`kind` expense|bill — vendor bills are expenses of kind `bill`) · `expense_lines` (billable, billed_invoice_id) · `vendor_payments` · `money_movements` (transfers, owner contributions/draws, loan proceeds/principal) · `bank_accounts` (→ chart account) · `import_batches` (bank CSV + migration) · `bank_transactions` (staging; `UNIQUE(business_id, bank_account_id, fingerprint)`) · `reconciliation_sessions`.

## Delivery, documents, operations
`private_documents` (tenant, path, mime, size, sha256) · `public_tokens` (sha256 `token_hash` unique, purpose, scoped resource ids, allowed actions, expiry, revocation, usage) · `email_outbox` (encrypted body, status lifecycle, attempts, `needs_review`, provider message id, document version reference) · `email_attempts` · `provider_events` (reserved for verified webhooks; `UNIQUE(provider, provider_event_id)`) · `document_access_events` · `audit_events` · `idempotency_keys` · `job_heartbeats` · Laravel `jobs`/`cache`.
