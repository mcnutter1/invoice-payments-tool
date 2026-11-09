<?php

namespace App\Services\Expenses;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Accounting\CashProjection;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use App\Services\Invoicing\InvoiceService;
use App\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly CashProjection $projection,
        private readonly Context $ctx,
    ) {}

    /** Accounts allowed to fund a paid expense. */
    public static function fundingAccounts(Business $b)
    {
        return ChartAccount::where('business_id', $b->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('subtype', ['bank', 'credit_card'])->orWhere('system_key', 'owner_contributions'))
            ->orderBy('code')->get();
    }

    public static function expenseAccounts(Business $b)
    {
        return ChartAccount::where('business_id', $b->id)->where('is_active', true)
            ->where(fn ($q) => $q->where('type', 'expense')->orWhere(fn ($q) => $q->where('type', 'asset')->where('subtype', 'fixed_asset')))
            ->orderBy('code')->get();
    }

    public function create(Business $business, array $data): Expense
    {
        return DB::transaction(function () use ($business, $data) {
            $kind = $data['kind'] === 'bill' ? 'bill' : 'expense';
            [$vendor, $client, $project, $date, $lines, $total, $funding] = $this->prepare($business, $kind, $data);

            $dupe = Expense::where('business_id', $business->id)->where('vendor_id', $vendor?->id)
                ->whereDate('expense_date', $date)->where('total_cents', $total)->whereNull('voided_at')->exists();

            $expense = new Expense([
                'kind' => $kind,
                'vendor_id' => $vendor?->id,
                'reference' => $data['reference'] ?? null,
                'expense_date' => $date->toDateString(),
                'due_date' => $kind === 'bill' ? ($data['due_date'] ?? $date->copy()->addDays(30)->toDateString()) : null,
                'description' => $data['description'] ?? null,
                'total_cents' => $total,
                'paid_cents' => $kind === 'expense' ? $total : 0,
                'status' => $kind === 'expense' ? 'paid' : 'unpaid',
                'payment_account_id' => $funding?->id,
                'payment_method' => $data['payment_method'] ?? null,
                'client_id' => $client?->id,
                'project_id' => $project?->id,
                'tax_treatment' => $data['tax_treatment'] ?? null,
                'notes' => $data['notes'] ?? null,
                'receipt_document_id' => $data['receipt_document_id'] ?? null,
                'possible_duplicate' => $dupe && ! in_array($data['source'] ?? 'manual', ['mileage', 'import'], true),
                'source' => $data['source'] ?? 'manual',
                'import_batch_id' => $data['import_batch_id'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'import_meta' => $data['import_meta'] ?? null,
                'created_by' => $this->ctx->user?->id,
            ]);
            $expense->business_id = $business->id;
            $expense->save();

            $this->writeLines($expense, $lines);
            $entry = $this->postEntry($business, $expense, $lines, $funding, $vendor, $client, 'expense:'.$expense->id);
            $expense->journal_entry_id = $entry->id;
            $expense->save();
            Audit::log($business, $kind.'.created', $expense, null, ['total_cents' => $total, 'source' => $expense->source]);

            return $expense;
        });
    }

    /**
     * Edit an expense. Amount, date, vendor, client, category or payment changes reverse the original
     * journal entry and post a corrected one; descriptive changes are saved in place.
     */
    public function update(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $expense = Expense::where('business_id', $expense->business_id)->lockForUpdate()->findOrFail($expense->id);
            $business = Business::findOrFail($expense->business_id);
            if ($expense->voided_at) throw new DomainException('Voided expenses can’t be edited.');
            $before = $expense->only(['vendor_id', 'client_id', 'project_id', 'expense_date', 'total_cents', 'payment_account_id', 'description', 'reference', 'notes']);
            $locked = ($expense->kind === 'bill' && $expense->vendorPayments()->where('status', 'posted')->exists())
                || ExpenseLine::where('expense_id', $expense->id)->whereNotNull('billed_invoice_id')->exists();

            [$vendor, $client, $project, $date, $lines, $total, $funding] = $this->prepare($business, $expense->kind, $data + ['payment_account_id' => $expense->payment_account_id]);
            $old = $expense->lines()->get();
            $financial = $expense->expense_date->toDateString() !== $date->toDateString() || $expense->total_cents !== $total
                || (int) $expense->vendor_id !== (int) $vendor?->id || (int) $expense->client_id !== (int) $client?->id
                || (int) $expense->payment_account_id !== (int) $funding?->id
                || $old->map(fn ($l) => $l->account_id.':'.$l->amount_cents.':'.(int) $l->billable)->sort()->values()->all()
                    !== collect($lines)->map(fn ($l) => $l['account']->id.':'.$l['amount'].':'.(int) $l['billable'])->sort()->values()->all();
            if ($financial && $locked) throw new DomainException('This entry has bill payments or was billed to a client, so only its description, notes, reference and project can change.');

            $expense->fill([
                'description' => $data['description'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'project_id' => $project?->id, 'payment_method' => $data['payment_method'] ?? $expense->payment_method,
                'tax_treatment' => $data['tax_treatment'] ?? $expense->tax_treatment, 'edited_at' => now(),
            ]);
            if (! empty($data['receipt_document_id'])) $expense->receipt_document_id = $data['receipt_document_id'];
            if ($financial) {
                $this->ledger->assertOpen($business, $expense->expense_date);
                $rev = $expense->revision + 1;
                $entry = JournalEntry::where('business_id', $business->id)->findOrFail($expense->journal_entry_id);
                $reversal = $this->ledger->reverse($entry, 'expense:'.$expense->id.':edit'.$rev.':reverse', $expense->expense_date, 'Edited');
                if ($expense->kind === 'expense') $this->projection->recordDirect($reversal, $expense->id);
                $expense->fill([
                    'vendor_id' => $vendor?->id, 'client_id' => $client?->id, 'expense_date' => $date->toDateString(), 'total_cents' => $total,
                    'payment_account_id' => $funding?->id, 'revision' => $rev,
                    'paid_cents' => $expense->kind === 'expense' ? $total : $expense->paid_cents,
                    'due_date' => $expense->kind === 'bill' ? ($data['due_date'] ?? $expense->due_date) : null,
                ]);
                $expense->save();
                ExpenseLine::where('expense_id', $expense->id)->delete();
                $this->writeLines($expense, $lines);
                $new = $this->postEntry($business, $expense, $lines, $funding, $vendor, $client, 'expense:'.$expense->id.':edit'.$rev);
                $expense->journal_entry_id = $new->id;
            }
            $expense->save();
            [$b, $a] = Audit::diff(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $before),
                array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $expense->only(array_keys($before))));
            Audit::log($business, 'expense.updated', $expense, $b, $a + ['reposted' => $financial]);

            return $expense;
        });
    }

    /** Validates and resolves the parts shared by create and update. */
    private function prepare(Business $business, string $kind, array $data): array
    {
        $vendor = ! empty($data['vendor_id']) ? Vendor::where('business_id', $business->id)->findOrFail($data['vendor_id']) : null;
        if ($kind === 'bill' && ! $vendor) throw new DomainException('Bills require a vendor.');
        $project = ! empty($data['project_id']) ? Project::where('business_id', $business->id)->findOrFail($data['project_id']) : null;
        $clientId = ! empty($data['client_id']) ? (int) $data['client_id'] : $project?->client_id;
        if ($project?->client_id && $clientId !== (int) $project->client_id) {
            throw new DomainException('The project “'.$project->name.'” belongs to a different client.');
        }
        $client = $clientId ? Client::where('business_id', $business->id)->findOrFail($clientId) : null;
        $date = Carbon::parse($data['expense_date']);
        $this->ledger->assertOpen($business, $date);

        $allowed = self::expenseAccounts($business)->keyBy('id');
        $lines = [];
        $total = 0;
        foreach ($data['lines'] as $l) {
            $amt = (int) $l['amount_cents'];
            if ($amt === 0) continue;
            if ($amt < 0) throw new DomainException('Expense lines must be positive. Record refunds from vendors as income or a credit.');
            $acct = $allowed[(int) $l['account_id']] ?? throw new DomainException('Choose an expense category for each line.');
            $lines[] = ['account' => $acct, 'amount' => $amt, 'description' => $l['description'] ?? null, 'billable' => ! empty($l['billable'])];
            $total += $amt;
        }
        if (! $lines) throw new DomainException('Enter at least one amount.');

        $funding = null;
        if ($kind === 'expense') {
            $funding = self::fundingAccounts($business)->firstWhere('id', (int) ($data['payment_account_id'] ?? 0))
                ?? throw new DomainException('Choose the account this expense was paid from.');
        }

        return [$vendor, $client, $project, $date, $lines, $total, $funding];
    }

    private function writeLines(Expense $expense, array $lines): void
    {
        foreach ($lines as $l) {
            ExpenseLine::create([
                'business_id' => $expense->business_id, 'expense_id' => $expense->id, 'account_id' => $l['account']->id,
                'description' => $l['description'], 'amount_cents' => $l['amount'], 'billable' => $l['billable'],
            ]);
        }
    }

    private function postEntry(Business $business, Expense $expense, array $lines, ?ChartAccount $funding, ?Vendor $vendor, ?Client $client, string $key): JournalEntry
    {
        $jl = [];
        foreach ($lines as $l) {
            $jl[] = ['account' => $l['account'], 'debit' => $l['amount'], 'vendor_id' => $vendor?->id, 'client_id' => $client?->id, 'description' => $l['description'] ?? $expense->description];
        }
        $credit = $expense->kind === 'expense' ? $funding : $business->account('ap');
        $jl[] = ['account' => $credit, 'credit' => $expense->total_cents, 'vendor_id' => $vendor?->id, 'description' => $vendor?->name ?? $expense->description];
        $memo = ($expense->kind === 'bill' ? 'Bill' : 'Expense').($vendor ? ' · '.$vendor->name : '').($expense->description ? ' · '.$expense->description : '');
        $entry = $this->ledger->post($business, $key, $expense->expense_date, $memo, $jl, ['source_type' => 'expense', 'source_id' => $expense->id]);
        if ($expense->kind === 'expense') {
            $this->projection->recordDirect($entry, $expense->id);
        }

        return $entry;
    }

    public function payBill(Expense $bill, int $cents, int $accountId, string $date, ?string $reference = null): VendorPayment
    {
        return DB::transaction(function () use ($bill, $cents, $accountId, $date, $reference) {
            $bill = Expense::where('business_id', $bill->business_id)->lockForUpdate()->findOrFail($bill->id);
            $business = Business::findOrFail($bill->business_id);
            if ($bill->kind !== 'bill' || $bill->voided_at) throw new DomainException('Only open bills can be paid.');
            if ($cents <= 0 || $cents > $bill->balanceCents()) throw new DomainException('Payment exceeds the bill balance.');
            $acct = self::fundingAccounts($business)->firstWhere('id', $accountId) ?? throw new DomainException('Choose a payment account.');
            $vp = VendorPayment::create([
                'business_id' => $business->id,
                'expense_id' => $bill->id,
                'amount_cents' => $cents,
                'payment_date' => $date,
                'account_id' => $acct->id,
                'reference' => $reference,
                'created_by' => $this->ctx->user?->id,
            ]);
            $entry = $this->ledger->post($business, 'vendor_payment:'.$vp->id, $date, 'Bill payment · '.($bill->vendor?->name ?? ''), [
                ['account' => $business->account('ap'), 'debit' => $cents, 'vendor_id' => $bill->vendor_id],
                ['account' => $acct, 'credit' => $cents, 'vendor_id' => $bill->vendor_id],
            ], ['source_type' => 'vendor_payment', 'source_id' => $vp->id]);
            $vp->journal_entry_id = $entry->id;
            $vp->save();
            $bill->paid_cents += $cents;
            $bill->status = $bill->paid_cents >= $bill->total_cents ? 'paid' : 'partial';
            $bill->save();
            $this->projection->syncBill($bill, $date, 'vendor_payment:'.$vp->id);
            Audit::log($business, 'bill.paid', $bill, null, ['amount_cents' => $cents]);

            return $vp;
        });
    }

    public function void(Expense $expense, string $reason): void
    {
        DB::transaction(function () use ($expense, $reason) {
            $expense = Expense::where('business_id', $expense->business_id)->lockForUpdate()->findOrFail($expense->id);
            if ($expense->voided_at) return;
            if ($expense->kind === 'bill' && $expense->vendorPayments()->where('status', 'posted')->exists()) {
                throw new DomainException('This bill has payments. Reverse them first.');
            }
            if (ExpenseLine::where('expense_id', $expense->id)->whereNotNull('billed_invoice_id')->exists()) {
                throw new DomainException('A line was billed to a client invoice. Credit that invoice first.');
            }
            $business = Business::findOrFail($expense->business_id);
            $entry = JournalEntry::where('business_id', $business->id)->findOrFail($expense->journal_entry_id);
            $rev = $this->ledger->reverse($entry, 'expense:'.$expense->id.':void', $this->ledger->openDate($business, $business->localToday()), $reason);
            if ($expense->kind === 'expense') {
                $this->projection->recordDirect($rev, $expense->id);
            }
            $expense->voided_at = now();
            $expense->status = 'void';
            $expense->save();
            Audit::log($business, 'expense.voided', $expense, null, null, $reason);
        });
    }

    /** Turn unbilled billable expense lines into lines on a draft invoice for the client. */
    public function billToClient(Expense $expense, InvoiceService $invoices): Invoice
    {
        return DB::transaction(function () use ($expense, $invoices) {
            $expense = Expense::where('business_id', $expense->business_id)->lockForUpdate()->findOrFail($expense->id);
            if (! $expense->client_id) throw new DomainException('Link this expense to a client first.');
            $lines = ExpenseLine::where('expense_id', $expense->id)->where('billable', true)->whereNull('billed_invoice_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new DomainException('No unbilled billable lines.');
            $business = Business::findOrFail($expense->business_id);
            $invoice = $invoices->saveDraft($business, null, [
                'client_id' => $expense->client_id,
                'lines' => $lines->map(fn ($l) => [
                    'name' => 'Reimbursable expense',
                    'description' => trim(($expense->vendor?->name ? $expense->vendor->name.' — ' : '').($l->description ?? $expense->description ?? '')),
                    'quantity' => '1',
                    'unit_price' => \App\Support\Money::toDecimalString($l->amount_cents),
                    'source_expense_line_id' => $l->id,
                    'tax_rate_id' => null,
                ])->all(),
            ]);
            ExpenseLine::whereIn('id', $lines->pluck('id'))->update(['billed_invoice_id' => $invoice->id]);
            Audit::log($business, 'expense.billed', $expense, null, ['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
