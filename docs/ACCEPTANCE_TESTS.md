# Acceptance tests

Run: `php artisan test` (SQLite in-memory, fast). Last local run: **38 tests, 317 assertions, all passing** (PHP 8.4, SQLite).

MySQL/MariaDB run (required by the spec; **not yet executed** — no local MySQL on the build machine):
```bash
mysql -uroot -e "CREATE DATABASE finance_test; CREATE USER 'finance_test'@'localhost' IDENTIFIED BY 'pw'; GRANT ALL ON finance_test.* TO 'finance_test'@'localhost';"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=finance_test DB_USERNAME=finance_test DB_PASSWORD=pw php artisan test
```
On the server this can be done with a scratch database; never point tests at `mcnutt_finance` (tests use `RefreshDatabase`).

Gateways are faked (`Http::fake`) — passing tests are **not** production verification of McNutt Cloud auth or notifications (see INTEGRATIONS.md sandbox checklist).

| Gate | Coverage | Status |
|---|---|---|
| 1 Tenant isolation / two tabs / altered IDs | `TenantSecurityTest::test_switching_and_altered_ids_never_leak_between_businesses`, `PublicInvoiceAndDeliveryTest::test_public_access_requires_grant_and_rejects_revoked_or_unrelated` | automated |
| 2 Central login & failures, outage | `AuthTest` (4 tests) | automated vs. fake; real provider **unverified** |
| 3 Roles, last owner, central admin | `TenantSecurityTest::test_roles_enforced_server_side`, `…last_owner…`, `…central_admin_role…` | automated |
| 4 Client/catalog/invoice/PDF/send | `PublicInvoiceAndDeliveryTest::test_send_view_report_without_financial_change`, `PagesSmokeTest`; PDF immutability by design (stored version, snapshots) | automated; **PDF is linked, not attached** (owner decision) |
| 5 Public view & payment report without financial change | `PublicInvoiceAndDeliveryTest::test_send_view_report_without_financial_change` | automated |
| 6 $400 of $1,000, idempotency, credits/overpay/returns/refunds | `AccountingTest::test_issue_posts_once_and_partial_payment_leaves_balance`, `…overpayment_refund_returned_check_and_credit_note` | automated |
| 7 Cash vs accrual golden numbers | `AccountingTest::test_cash_vs_accrual_golden_scenario`, `…cumulative_cash_recognition_converges_exactly` | automated |
| 8 Non-P&L activity, cutoffs, control accounts | `AccountingTest::test_non_pnl_activity_and_deposits_do_not_create_revenue`, `MigrationTest` | automated |
| 9 Contracts & signatures | `SignatureTest` (3 tests) | automated |
| 10 Recurring | `RecurringTest` (4 tests); concurrency simulated sequentially with the unique run key | automated (true parallel cron not exercised) |
| 11 Accepted vs delivered, scanners, ambiguous sends | `PublicInvoiceAndDeliveryTest::test_outbox_classifies_provider_results`, `…voided_invoice_cancels_queued_sends` | automated; no callbacks exist |
| 12 CSV import/reconcile/migration cutoff | `BankingTest` (2), `MigrationTest` | automated |
| 13 Reports tie out, closed periods | `AccountingTest::test_void_reverses_and_closed_period_rejects_backdating`, `…ar_aging_buckets_as_of`, `PagesSmokeTest` (all 14 reports render) | automated; drill-down totals manual |
| 14 Mobile/a11y, multi-page PDFs | Manual: public invoice and editor checked at narrow width; 70-line invoice rendered to 6 pages with repeated headers and totals kept together | manual, partial — no automated a11y/browser tests |
| 15 Clean install, cron, backups | `deploy.sh` (`bash -n` checked); restore drill script | **pending** first server run and restore drill |

Golden fixtures (`tests/Unit/InvoiceMathTest.php`): money parse/format, largest-remainder allocation with ties, half-up line rounding, mixed taxable/non-taxable, discount before tax with deterministic residual, percent discount + exempt client, zero/negative totals. Partial receipts, credit taxes, refunds, deposits and as-of aging are in `AccountingTest`.

## Manual smoke after deploy
1. `/health` returns `{"status":"ok"}`; `php artisan finance:preflight` passes.
2. Sign in via McNutt Cloud; create a business; add a client and product.
3. Create, preview, issue and send an invoice to yourself; open the email links on a phone; report a Zelle payment.
4. Confirm the payment; check Reports → P&L (cash and accrual) and A/R aging.
5. Send a one-signer contract to yourself; sign with the emailed code; download signed PDF + certificate.
6. Run `sudo /usr/local/sbin/mcnutt-finance-backup.sh` and `…-restore-check.sh`; record in BACKUP_RESTORE.md.
