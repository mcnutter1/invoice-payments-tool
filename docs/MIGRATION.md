# Migration from QuickBooks / spreadsheets

Settings → **Data import** (requires `clients.manage` and `journals.manage`). Each import: download template → upload CSV → dry-run preview (new / duplicate / error per row) → import. Batches are recorded in `import_batches` with counts and are audited. Duplicate keys: clients by `external_id` (else display name), catalog by SKU (else name), opening A/R by invoice number, expenses by date+vendor+amount+reference.

## Recommended order and cutoff
Pick a **cutoff date** = the last day of books kept elsewhere (e.g. 2025-12-31). Choose one approach per cutoff — never both:
* **Opening-summary (recommended):** import only balances at the cutoff. History stays in QuickBooks.
* **Transactional history:** enter/import historical invoices and payments as real transactions instead — then do **not** import opening A/R for the same period.

1. Clients (`clients` template).
2. Products & services (`catalog`; `income_account_code` uses this app's chart codes, e.g. 4000).
3. Bank accounts: Banking → add account with the opening balance as of the cutoff (posts to Opening Balance Equity).
4. Opening A/R (`opening_ar`): one row per unpaid invoice at cutoff with the **open** amount. Each becomes issued invoice `OB-<number>` dated the original invoice date; the ledger entry (dated the cutoff) is Dr A/R / Cr Opening Balance Equity, so prior-period revenue is not counted again. Clients can be emailed these invoices and pay them normally; collections after cutoff are cash-basis income.
5. Opening A/P and other balances (credit cards, loans, fixed assets, sales tax payable): enter a manual journal on the cutoff date against Opening Balance Equity, or record open vendor bills as Bills dated before the cutoff.
6. Historical expenses (`expenses`, optional): posts paid expenses normally (use only for periods not already summarized by opening balances).
7. Close the books through the cutoff (Accounting → Period close).

After import: Trial balance as of the cutoff should match the QuickBooks balance sheet; Opening Balance Equity should then be reclassified to Owner Equity/Retained Earnings by your accountant with a journal.

## QuickBooks export mapping
| QuickBooks report/export | Template | Mapping |
|---|---|---|
| Customer Contact List (CSV) | clients | Customer → display_name, Company → legal_name, Email, Phone, Billing Street/City/State/ZIP, Terms (days) → default_terms_days, Customer ID (if exported) → external_id |
| Product/Service List | catalog | Product/Service → name, SKU, Type (Service/Inventory/Non-inventory → service/product), Sales Description → description, Sales Price → default_price, Income Account → income_account_code (map names to codes) |
| Open Invoices (as of cutoff) | opening_ar | Customer → client (must equal imported display_name), Num → invoice_number, Date → invoice_date, Due Date, Open Balance → open_amount |
| Transaction List by Date (Expense/Check) | expenses | Date, Name → vendor, Account → category_code, Memo → description, Amount (positive), Payment account → paid_from_code, Num → reference |
| Balance Sheet (as of cutoff) | manual journal | One journal against Opening Balance Equity |

Dates: ISO `YYYY-MM-DD` or US `MM/DD/YYYY`. Amounts may include `$` and commas. This is a CSV mapping guide; there is no proprietary one-click QuickBooks importer. Validate with real exported files before relying on the column names above.

Not yet automated: importing historical payment allocations (record receipts against `OB-` invoices with Receive payment).
