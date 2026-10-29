# Accounting policies

These are the policies the software implements. They are management-accounting defaults for small service businesses; review them with your accountant. Nothing here is tax advice.

## Ledger
* One canonical double-entry ledger per business. Every posting balances to the cent, references its source document and is unique per `(business, source_key)` — retries and double clicks cannot post twice.
* Posted entries are never edited or deleted. Corrections are reversing/adjusting entries with actor and reason.
* Closed periods: no posting (including reversals) may be dated on or before `books_closed_through`. Reopening requires `books.reopen`, a reason, fresh sign-in, and is audited.
* A/R and A/P are control accounts: they only change through invoices, credit notes, payments, bills and bill payments (manual journals to them are blocked).

## Rounding
1. Line amount = round half-up(quantity × unit price) to cents.
2. A document-level discount (percent or amount, capped at subtotal) is allocated to lines in proportion to line amounts; leftover cents go to the largest fractional remainders, ties in line order.
3. Tax is exclusive: per line, round half-up(discounted line × rate).
4. Total = Σ discounted lines + Σ line tax. Results are stored per line; the browser estimate is never trusted.
Inclusive or compound taxes are not supported. Negative lines/invoices are rejected — use credit notes. Zero-total invoices are allowed (no payment call to action).

## Postings
| Event | Debit | Credit |
|---|---|---|
| Issue invoice (accounting date = invoice date) | A/R (total) | Income accounts (net of discount, per line account) + Sales Tax Payable |
| Payment received and applied | Bank, or Undeposited Funds for checks/cash | A/R (applied) + Customer Advances (unapplied remainder) |
| Apply customer credit later | Customer Advances | A/R |
| Unapply (reallocation) | A/R | Customer Advances |
| Deposit check | Bank | Undeposited Funds |
| Refund of customer credit | Customer Advances | Bank |
| Returned check / payment reversal | A/R (each active allocation) + Customer Advances (unapplied part) | Bank (or Undeposited Funds if never deposited) |
| Credit note | Income (net) + Sales Tax Payable (tax) | A/R |
| Write-off (bad debt) | Bad Debt expense (full open amount incl. tax) | A/R |
| Void invoice (no active allocations) | reversal of the issue entry, dated today (or first open day) |
| Paid expense | Expense/asset lines | Bank, credit card, or Owner Contributions (paid personally) |
| Vendor bill | Expense/asset lines | A/P |
| Bill payment | A/P | Bank / card |
| Transfer | Destination account | Source account |
| Owner contribution / draw | Bank / Owner Draws | Owner Contributions / Bank |
| Loan proceeds / principal payment | Bank / Loans Payable | Loans Payable / Bank |
| Opening bank balance | Bank | Opening Balance Equity |
| Opening A/R (migration) | A/R | Opening Balance Equity |

Check receipt-date policy: a check is recorded on the date received (held in Undeposited Funds), moved to the bank when deposited; "cleared" is a status only. Deposit/clearing never recognizes revenue again.

Write-off policy: the full open amount (including any tax) is expensed to Bad Debt; if you can reclaim previously remitted sales tax, post a manual adjustment to Sales Tax Payable.

Customer deposits received before an invoice are liabilities (Customer Advances), not income. Applying them to an invoice recognizes nothing extra on accrual (revenue was recognized at issue) and recognizes cash-basis income on the application date.

Revenue recognition: service revenue is recognized on the invoice accounting date. Service-period dates are stored for reference; prepaid/unearned work should be deferred by the accountant with manual journals (Customer Advances / an unearned-revenue liability). Automated revenue recognition is not provided.

## Cash basis (management projection)
The cash-basis P&L is a traceable projection of the accrual ledger, stored as `cash_basis_projection_events`:
* **Invoice income & tax** are recognized in proportion to *cumulative cash applied* to the invoice: target(account) = allocate(cumulative cash applied, weights = each income account's net and the invoice tax). The event recorded is the change versus what was already recognized, dated on the application date. Rounding therefore always converges exactly to the invoice composition when fully paid, and reversals (bounced checks, unapply) produce negative events in the period they happen.
* Credit notes and write-offs are non-cash and create no cash-basis events; they reduce what remains collectible.
* **Paid expenses**, categorized bank rows and manual journals with a bank/undeposited/credit-card leg are recognized on their entry date. **Bills** are recognized in proportion to bill payments, on the payment date.
* Unapplied customer advances are not income until applied.
* Opening A/R collected after the cutoff is cash-basis income when collected (it was never in this app's accrual P&L).

Example (acceptance gate 7): $1,000 + $100 tax invoice, $550 received, $200 paid expense → cash P&L income $500 (tax $50 to liability), profit $300; accrual income $1,000, profit $800.

Cash reports are labeled "management view" and are not tax returns. Balance sheet and trial balance are accrual only.

## Reports
Reports show business, basis, currency (USD), period/as-of date and generation time; amounts drill down to GL lines (or projection events for cash). Drafts, voided effects (net of reversals) and unconfirmed payment claims are excluded. Dates are business-local calendar dates; accounting dates are `DATE` fields separate from UTC event timestamps. The balance sheet computes prior-years retained earnings and current-fiscal-year net income from the ledger (no year-end closing entry needed).

## Currency
USD only. Every financial record stores `currency`; allocations across currencies are rejected.
