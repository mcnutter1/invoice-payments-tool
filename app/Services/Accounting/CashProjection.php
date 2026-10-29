<?php

namespace App\Services\Accounting;

use App\Models\ChartAccount;
use App\Models\CashProjectionEvent;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Cash-basis management projection derived from the canonical ledger (docs/ACCOUNTING_POLICIES.md#cash-basis).
 * Invoice income and tax are recognized proportionally to cumulative cash applied, so rounding always
 * converges exactly to the invoice composition when fully paid.
 */
class CashProjection
{
    /** Re-sync an invoice's cash recognition to its current cumulative cash allocations. */
    public function syncInvoice(Invoice $invoice, Carbon|string $date, string $sourceKey): void
    {
        $invoice->loadMissing('lines');
        if ($invoice->total_cents <= 0) return;

        $cash = (int) PaymentAllocation::where('business_id', $invoice->business_id)
            ->where('invoice_id', $invoice->id)->where('status', 'active')->whereNotNull('payment_id')
            ->sum('amount_cents');

        $weights = [];
        foreach ($invoice->lines as $l) {
            $weights[$l->income_account_id] = ($weights[$l->income_account_id] ?? 0) + $l->net_cents;
        }
        if ($invoice->tax_cents > 0) {
            $taxId = ChartAccount::where('business_id', $invoice->business_id)->where('system_key', 'sales_tax')->value('id');
            $weights[$taxId] = ($weights[$taxId] ?? 0) + $invoice->tax_cents;
        }
        // Allocate the cumulative cash share of the total across components.
        $cash = min($cash, $invoice->total_cents);
        $targets = $this->proportional($cash, $invoice->total_cents, $weights);

        $already = CashProjectionEvent::where('business_id', $invoice->business_id)->where('invoice_id', $invoice->id)
            ->selectRaw('account_id, SUM(amount_cents) as s')->groupBy('account_id')->pluck('s', 'account_id');

        foreach ($targets as $accountId => $target) {
            $delta = $target - (int) ($already[$accountId] ?? 0);
            if ($delta === 0) continue;
            CashProjectionEvent::create([
                'business_id' => $invoice->business_id,
                'event_date' => Carbon::parse($date)->toDateString(),
                'account_id' => $accountId,
                'amount_cents' => $delta,
                'source_key' => $sourceKey.':inv'.$invoice->id,
                'invoice_id' => $invoice->id,
                'description' => 'Cash receipt · '.$invoice->displayNumber(),
            ]);
        }
    }

    /** Bills (AP): recognize expense lines proportionally to cumulative payments. */
    public function syncBill(Expense $bill, Carbon|string $date, string $sourceKey): void
    {
        $bill->loadMissing('lines');
        if ($bill->total_cents <= 0) return;
        $paid = (int) $bill->vendorPayments()->where('status', 'posted')->sum('amount_cents');
        $weights = [];
        foreach ($bill->lines as $l) {
            $weights[$l->account_id] = ($weights[$l->account_id] ?? 0) + $l->amount_cents;
        }
        $targets = $this->proportional(min($paid, $bill->total_cents), $bill->total_cents, $weights);
        $already = CashProjectionEvent::where('business_id', $bill->business_id)->where('expense_id', $bill->id)
            ->selectRaw('account_id, SUM(amount_cents) as s')->groupBy('account_id')->pluck('s', 'account_id');
        foreach ($targets as $accountId => $target) {
            $delta = $target - (int) ($already[$accountId] ?? 0);
            if ($delta === 0) continue;
            CashProjectionEvent::create([
                'business_id' => $bill->business_id,
                'event_date' => Carbon::parse($date)->toDateString(),
                'account_id' => $accountId,
                'amount_cents' => $delta,
                'source_key' => $sourceKey.':bill'.$bill->id,
                'expense_id' => $bill->id,
                'description' => 'Bill payment · '.($bill->reference ?: '#'.$bill->id),
            ]);
        }
    }

    /** Entries that move cash directly (paid expenses, categorized bank rows, cash manual journals). */
    public function recordDirect(JournalEntry $entry, ?int $expenseId = null): void
    {
        $entry->loadMissing('lines.account');
        foreach ($entry->lines as $l) {
            $type = $l->account->type;
            if (! in_array($type, ['income', 'expense'], true) && $l->account->system_key !== 'sales_tax') continue;
            $amount = $type === 'expense' ? $l->debit_cents - $l->credit_cents : $l->credit_cents - $l->debit_cents;
            if ($amount === 0) continue;
            $existing = CashProjectionEvent::where('business_id', $entry->business_id)
                ->where('source_key', 'je:'.$entry->id)->where('account_id', $l->account_id)->first();
            if ($existing) {
                $existing->increment('amount_cents', $amount);
                continue;
            }
            CashProjectionEvent::create([
                'business_id' => $entry->business_id,
                'event_date' => $entry->entry_date->toDateString(),
                'account_id' => $l->account_id,
                'amount_cents' => $amount,
                'source_key' => 'je:'.$entry->id,
                'journal_entry_id' => $entry->id,
                'expense_id' => $expenseId,
                'description' => $entry->memo,
            ]);
        }
    }

    /** True when the entry has a leg on a cash-like account (bank, undeposited, credit card, owner-paid). */
    public function touchesCash(JournalEntry $entry): bool
    {
        $entry->loadMissing('lines.account');

        return $entry->lines->contains(fn ($l) => in_array($l->account->subtype, ['bank', 'undeposited', 'credit_card'], true));
    }

    /** @param array<int,int> $weights */
    private function proportional(int $cash, int $total, array $weights): array
    {
        if ($total <= 0) return array_fill_keys(array_keys($weights), 0);
        // Exact component share of cumulative cash, rounded deterministically.
        return Money::allocate($cash, $weights);
    }
}
