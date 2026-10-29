<?php

namespace App\Services\Invoicing;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceDocumentVersion;
use App\Models\InvoiceSequence;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Accounting\CashProjection;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceCalculator $calc,
        private readonly Ledger $ledger,
        private readonly CashProjection $projection,
        private readonly PdfRenderer $pdf,
        private readonly DocumentStore $docs,
        private readonly InvoicePresenter $presenter,
        private readonly Context $ctx,
    ) {}

    /**
     * Create or update a draft. $data is already validated for shape; this enforces tenant and state rules.
     */
    public function saveDraft(Business $business, ?Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($business, $invoice, $data) {
            if ($invoice) {
                $invoice = Invoice::where('business_id', $business->id)->lockForUpdate()->findOrFail($invoice->id);
                if (! $invoice->isDraft()) {
                    throw new DomainException('Only draft invoices can be edited. Use void/reissue or a credit note.');
                }
                if (isset($data['lock_version']) && (int) $data['lock_version'] !== (int) $invoice->lock_version) {
                    throw new DomainException('This draft was changed by someone else. Reload to see the latest version.');
                }
            }
            $client = Client::where('business_id', $business->id)->findOrFail($data['client_id']);

            [$contractId, $versionId] = $this->resolveContractLink($business, $client, $data['contract_version_id'] ?? null);

            $lines = $this->normalizeLines($business, $data['lines'] ?? []);
            $totals = $this->calc->calculate($lines, $data['discount_type'] ?? 'none', $data['discount_value'] ?? 0, (bool) $client->tax_exempt);
            $issueDate = ! empty($data['issue_date']) ? Carbon::parse($data['issue_date']) : null;
            $terms = (int) ($client->default_terms_days ?? $business->default_terms_days);
            $dueDate = ! empty($data['due_date']) ? Carbon::parse($data['due_date']) : ($issueDate ? $issueDate->copy()->addDays($terms) : null);

            $attrs = [
                'client_id' => $client->id,
                'issue_date' => $issueDate?->toDateString(),
                'due_date' => $dueDate?->toDateString(),
                'service_start' => $data['service_start'] ?? null,
                'service_end' => $data['service_end'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'contract_id' => $contractId,
                'contract_version_id' => $versionId,
                'discount_type' => $data['discount_type'] ?? 'none',
                'discount_value' => $data['discount_value'] ?? 0,
                'subtotal_cents' => $totals['subtotal_cents'],
                'discount_cents' => $totals['discount_cents'],
                'tax_cents' => $totals['tax_cents'],
                'total_cents' => $totals['total_cents'],
                'balance_cents' => $totals['total_cents'],
                'customer_note' => $data['customer_note'] ?? $business->default_customer_note,
                'terms' => $data['terms'] ?? $business->default_terms_text,
                'internal_notes' => $data['internal_notes'] ?? null,
                'payment_methods' => array_values(array_intersect($data['payment_methods'] ?? $business->allowedMethods(), $business->allowedMethods())),
                'recipients' => $this->cleanRecipients($data['recipients'] ?? null, $client),
            ];
            if ($invoice) {
                $invoice->fill($attrs);
                $invoice->lock_version++;
                $invoice->save();
                $invoice->lines()->delete();
                $invoice->taxLines()->delete();
            } else {
                $invoice = new Invoice($attrs + ['currency' => 'USD', 'status' => 'draft', 'created_by' => $this->ctx->user?->id]);
                $invoice->business_id = $business->id;
                if (! empty($data['recurring_schedule_id'])) $invoice->recurring_schedule_id = $data['recurring_schedule_id'];
                $invoice->save();
            }
            foreach ($totals['lines'] as $i => $l) {
                $invoice->lines()->create([
                    'business_id' => $business->id,
                    'position' => $i,
                    'product_id' => $l['product_id'] ?? null,
                    'sku' => $l['sku'] ?? null,
                    'name' => $l['name'],
                    'description' => $l['description'] ?? null,
                    'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'],
                    'unit' => $l['unit'] ?? null,
                    'income_account_id' => $l['income_account_id'],
                    'tax_rate_id' => $l['tax_rate_id'] ?? null,
                    'tax_name' => $l['tax_name'] ?? null,
                    'tax_rate' => $l['tax_rate'],
                    'amount_cents' => $l['amount_cents'],
                    'discount_cents' => $l['discount_cents'],
                    'net_cents' => $l['net_cents'],
                    'tax_cents' => $l['tax_cents'],
                    'contract_milestone_id' => $l['contract_milestone_id'] ?? null,
                    'source_expense_line_id' => $l['source_expense_line_id'] ?? null,
                ]);
            }
            foreach ($totals['tax_lines'] as $t) {
                $invoice->taxLines()->create($t + ['business_id' => $business->id]);
            }

            return $invoice->fresh(['lines', 'taxLines']);
        });
    }

    /** Validate/normalize lines against tenant-owned catalog, accounts and tax rates. */
    private function normalizeLines(Business $business, array $lines): array
    {
        $out = [];
        $defaultIncome = $business->account('service_income')->id;
        foreach ($lines as $l) {
            if (trim((string) ($l['name'] ?? '')) === '' && empty($l['product_id'])) continue;
            $product = ! empty($l['product_id']) ? Product::where('business_id', $business->id)->findOrFail($l['product_id']) : null;
            $incomeId = $l['income_account_id'] ?? $product?->income_account_id ?? $defaultIncome;
            $acct = ChartAccount::where('business_id', $business->id)->where('type', 'income')->find($incomeId);
            if (! $acct) throw new DomainException('Invalid income account on a line.');
            $taxRateId = array_key_exists('tax_rate_id', $l) ? ($l['tax_rate_id'] ?: null) : $product?->tax_rate_id;
            $tax = $taxRateId ? TaxRate::where('business_id', $business->id)->findOrFail($taxRateId) : null;
            $out[] = [
                'product_id' => $product?->id,
                'sku' => $product?->sku,
                'name' => trim((string) ($l['name'] ?? '')) ?: $product->name,
                'description' => $l['description'] ?? $product?->description,
                'quantity' => (string) ($l['quantity'] ?? '1'),
                'unit_price' => (string) ($l['unit_price'] ?? $product?->default_price ?? '0'),
                'unit' => $l['unit'] ?? $product?->unit,
                'income_account_id' => $acct->id,
                'tax_rate_id' => $tax?->id,
                'tax_name' => $tax?->name,
                'tax_rate' => $tax ? (string) $tax->rate : '0',
                'contract_milestone_id' => $l['contract_milestone_id'] ?? null,
                'source_expense_line_id' => $l['source_expense_line_id'] ?? null,
            ];
        }

        return $out;
    }

    private function cleanRecipients(?array $recipients, Client $client): array
    {
        $list = array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $recipients ?: [$client->loadMissing('contacts')->billingEmail()]
        ), fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match('/[\r\n]/', $e))));

        return array_slice($list, 0, 10);
    }

    private function resolveContractLink(Business $business, Client $client, mixed $versionId): array
    {
        if (! $versionId) return [null, null];
        $v = ContractVersion::where('business_id', $business->id)->findOrFail($versionId);
        $contract = Contract::where('business_id', $business->id)->findOrFail($v->contract_id);
        if ($contract->client_id !== $client->id) {
            throw new DomainException('The linked agreement belongs to a different client.');
        }

        return [$contract->id, $v->id];
    }

    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::where('business_id', $invoice->business_id)->lockForUpdate()->findOrFail($invoice->id);
            $business = Business::findOrFail($invoice->business_id);
            if ($invoice->isIssued()) {
                return $invoice; // idempotent
            }
            if (! $invoice->isDraft()) {
                throw new DomainException('Only drafts can be issued.');
            }
            if (! $business->isActive()) {
                throw new DomainException('This business is not active.');
            }
            $invoice->load('lines', 'client.contacts');
            if ($invoice->lines->isEmpty()) {
                throw new DomainException('Add at least one line before issuing.');
            }
            if ($invoice->total_cents < 0) {
                throw new DomainException('Negative invoices are not allowed. Use a credit note.');
            }
            $this->checkContractCap($invoice);

            $issueDate = $invoice->issue_date ?? $business->localToday();
            $this->ledger->assertOpen($business, $issueDate);
            $terms = (int) ($invoice->client->default_terms_days ?? $business->default_terms_days);

            $invoice->number = $this->nextNumber($business, 'invoice', $business->invoice_prefix);
            $invoice->issue_date = $issueDate;
            $invoice->due_date = $invoice->due_date ?? $issueDate->copy()->addDays($terms);
            $invoice->status = 'issued';
            $invoice->issued_at = now();
            $invoice->issued_by = $this->ctx->user?->id;
            $invoice->business_snapshot = InvoicePresenter::businessSnapshot($business);
            $invoice->client_snapshot = $invoice->client->snapshot();
            $invoice->instructions_snapshot = InvoicePresenter::instructionsSnapshot($business, $invoice->payment_methods ?? []);
            $invoice->template_snapshot = InvoicePresenter::templateSnapshot($business);
            $invoice->save();

            if ($invoice->total_cents > 0) {
                $ar = $business->account('ar');
                $lines = [['account' => $ar, 'debit' => $invoice->total_cents, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => 'Invoice '.$invoice->number]];
                $byAccount = [];
                foreach ($invoice->lines as $l) {
                    $byAccount[$l->income_account_id] = ($byAccount[$l->income_account_id] ?? 0) + $l->net_cents;
                }
                foreach ($byAccount as $acct => $cents) {
                    $lines[] = ['account' => $acct, 'credit' => $cents, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => 'Invoice '.$invoice->number];
                }
                if ($invoice->tax_cents > 0) {
                    $lines[] = ['account' => $business->account('sales_tax'), 'credit' => $invoice->tax_cents, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => 'Sales tax · '.$invoice->number];
                }
                $entry = $this->ledger->post($business, 'invoice:'.$invoice->id.':issue', $issueDate, 'Invoice '.$invoice->number.' · '.$invoice->client->display_name, $lines, [
                    'source_type' => 'invoice', 'source_id' => $invoice->id,
                ]);
                $invoice->issue_journal_entry_id = $entry->id;
                $invoice->save();
            }
            $this->markMilestones($invoice);
            $this->refreshBalance($invoice);
            $this->generatePdf($invoice, 'issue');
            Audit::log($business, 'invoice.issued', $invoice, null, ['number' => $invoice->number, 'total_cents' => $invoice->total_cents]);

            return $invoice->fresh();
        });
    }

    private function checkContractCap(Invoice $invoice): void
    {
        if (! $invoice->contract_id) return;
        $contract = Contract::where('business_id', $invoice->business_id)->find($invoice->contract_id);
        if (! $contract || ! $contract->value_cap_cents) return;
        $after = $contract->invoicedCents() + $invoice->total_cents;
        if ($after > $contract->value_cap_cents && $contract->cap_behavior === 'block') {
            throw new DomainException('Issuing this invoice would exceed the agreement value cap of '.Money::format($contract->value_cap_cents).'.');
        }
    }

    private function markMilestones(Invoice $invoice): void
    {
        $ids = $invoice->lines->pluck('contract_milestone_id')->filter()->all();
        if ($ids) {
            \App\Models\ContractMilestone::where('business_id', $invoice->business_id)->whereIn('id', $ids)
                ->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);
        }
    }

    public function nextNumber(Business $business, string $kind, string $prefix): string
    {
        $seq = InvoiceSequence::where('business_id', $business->id)->where('kind', $kind)->lockForUpdate()->first();
        if (! $seq) {
            $seq = InvoiceSequence::create(['business_id' => $business->id, 'kind' => $kind, 'next_number' => 1]);
            $seq = InvoiceSequence::whereKey($seq->id)->lockForUpdate()->first();
        }
        $n = $seq->next_number;
        $seq->next_number = $n + 1;
        $seq->save();

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function generatePdf(Invoice $invoice, string $reason): InvoiceDocumentVersion
    {
        $vm = $this->presenter->viewModel($invoice->fresh());
        $bytes = $this->pdf->render('pdf.invoice', $vm);
        $doc = $this->docs->put($invoice->business, 'invoice_pdf', ($invoice->number ?? 'invoice').'.pdf', $bytes, 'application/pdf');
        $version = (int) InvoiceDocumentVersion::where('invoice_id', $invoice->id)->max('version') + 1;

        return InvoiceDocumentVersion::create([
            'business_id' => $invoice->business_id,
            'invoice_id' => $invoice->id,
            'version' => $version,
            'document_id' => $doc->id,
            'sha256' => $doc->sha256,
            'reason' => $reason,
        ]);
    }

    public function refreshBalance(Invoice $invoice): Invoice
    {
        $allocs = PaymentAllocation::where('business_id', $invoice->business_id)->where('invoice_id', $invoice->id)->where('status', 'active')->get();
        $paid = (int) $allocs->whereNotNull('payment_id')->sum('amount_cents');
        $creditIds = $allocs->whereNotNull('credit_note_id')->pluck('credit_note_id');
        $kinds = CreditNote::whereIn('id', $creditIds)->pluck('kind', 'id');
        $credited = 0;
        $written = 0;
        foreach ($allocs->whereNotNull('credit_note_id') as $a) {
            if (($kinds[$a->credit_note_id] ?? 'credit') === 'write_off') $written += $a->amount_cents;
            else $credited += $a->amount_cents;
        }
        $balance = $invoice->total_cents - $paid - $credited - $written;
        $invoice->paid_cents = $paid;
        $invoice->credited_cents = $credited;
        $invoice->written_off_cents = $written;
        $invoice->balance_cents = $invoice->status === 'void' ? 0 : $balance;
        $invoice->settlement_status = match (true) {
            $invoice->status !== 'issued' => 'unpaid',
            $balance <= 0 && $written > 0 && $paid === 0 => 'written_off',
            $balance <= 0 => 'paid',
            $paid > 0 || $credited > 0 => 'partial',
            default => 'unpaid',
        };
        $invoice->save();

        return $invoice;
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::where('business_id', $invoice->business_id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'void') return $invoice;
            if ($invoice->isDraft()) throw new DomainException('Drafts can be deleted instead of voided.');
            $active = PaymentAllocation::where('invoice_id', $invoice->id)->where('status', 'active')->count();
            if ($active > 0) {
                throw new DomainException('Remove payment and credit allocations before voiding this invoice.');
            }
            $business = Business::findOrFail($invoice->business_id);
            if ($invoice->issue_journal_entry_id) {
                $entry = \App\Models\JournalEntry::where('business_id', $business->id)->findOrFail($invoice->issue_journal_entry_id);
                $this->ledger->reverse($entry, 'invoice:'.$invoice->id.':void', $this->ledger->openDate($business, $business->localToday()), $reason);
            }
            $invoice->status = 'void';
            $invoice->voided_at = now();
            $invoice->voided_by = $this->ctx->user?->id;
            $invoice->void_reason = $reason;
            $invoice->save();
            \App\Models\PublicToken::where('invoice_id', $invoice->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            \App\Models\ContractMilestone::where('business_id', $business->id)->where('invoice_id', $invoice->id)->update(['status' => 'pending', 'invoice_id' => null]);
            \App\Models\ExpenseLine::where('business_id', $business->id)->where('billed_invoice_id', $invoice->id)->update(['billed_invoice_id' => null]);
            $this->refreshBalance($invoice);
            Audit::log($business, 'invoice.voided', $invoice, null, null, $reason);

            return $invoice;
        });
    }

    /** Copy an invoice into a new draft (used for void & reissue). */
    public function duplicateAsDraft(Invoice $invoice): Invoice
    {
        $invoice->load('lines');
        $data = [
            'client_id' => $invoice->client_id,
            'po_number' => $invoice->po_number,
            'contract_version_id' => $invoice->contract_version_id,
            'discount_type' => $invoice->discount_type,
            'discount_value' => (string) $invoice->discount_value,
            'customer_note' => $invoice->customer_note,
            'terms' => $invoice->terms,
            'payment_methods' => $invoice->payment_methods,
            'recipients' => $invoice->recipients,
            'service_start' => $invoice->service_start?->toDateString(),
            'service_end' => $invoice->service_end?->toDateString(),
            'lines' => $invoice->lines->map(fn ($l) => [
                'product_id' => $l->product_id, 'name' => $l->name, 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'unit' => $l->unit,
                'income_account_id' => $l->income_account_id, 'tax_rate_id' => $l->tax_rate_id,
            ])->all(),
        ];
        $new = $this->saveDraft($invoice->business, null, $data);
        $new->replaces_invoice_id = $invoice->id;
        $new->save();

        return $new;
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::where('business_id', $invoice->business_id)->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isDraft()) throw new DomainException('Only drafts can be deleted.');
            $invoice->lines()->delete();
            $invoice->taxLines()->delete();
            \App\Models\ScheduleRun::where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'status' => 'skipped']);
            \App\Models\ExpenseLine::where('business_id', $invoice->business_id)->where('billed_invoice_id', $invoice->id)->update(['billed_invoice_id' => null]);
            $invoice->delete();
            Audit::log($invoice->business_id, 'invoice.draft_deleted', $invoice);
        });
    }

    /**
     * Credit note or write-off against an issued invoice.
     *
     * @param list<array{description:string, income_account_id?:int, amount_cents:int, tax_rate?:string}> $lines
     */
    public function credit(Invoice $invoice, array $lines, string $reason, string $kind = 'credit', ?string $date = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $lines, $reason, $kind, $date) {
            $invoice = Invoice::where('business_id', $invoice->business_id)->lockForUpdate()->findOrFail($invoice->id);
            $business = Business::findOrFail($invoice->business_id);
            if (! $invoice->isIssued()) throw new DomainException('Credits apply only to issued invoices.');
            $this->refreshBalance($invoice);
            $d = $date ? Carbon::parse($date) : $business->localToday();
            $this->ledger->assertOpen($business, $d);

            $sub = 0;
            $tax = 0;
            $norm = [];
            if ($kind === 'write_off') {
                $amount = (int) ($lines[0]['amount_cents'] ?? 0);
                if ($amount <= 0 || $amount > $invoice->balance_cents) throw new DomainException('Write-off must be between $0.01 and the open balance.');
                $norm[] = ['description' => 'Bad debt write-off', 'income_account_id' => $business->account('bad_debt')->id, 'amount_cents' => $amount, 'tax_rate' => '0', 'tax_cents' => 0];
                $sub = $amount;
            } else {
                foreach ($lines as $l) {
                    $amt = (int) $l['amount_cents'];
                    if ($amt <= 0) continue;
                    $acct = ChartAccount::where('business_id', $business->id)->where('type', 'income')->findOrFail($l['income_account_id'] ?? $business->account('service_income')->id);
                    $rate = (string) ($l['tax_rate'] ?? '0');
                    $t = $rate !== '0' && $rate !== '' ? Money::percentOf($amt, $rate) : 0;
                    $norm[] = ['description' => $l['description'], 'income_account_id' => $acct->id, 'amount_cents' => $amt, 'tax_rate' => $rate ?: '0', 'tax_cents' => $t];
                    $sub += $amt;
                    $tax += $t;
                }
                if (! $norm) throw new DomainException('Enter at least one credit amount.');
                if ($sub + $tax > $invoice->balance_cents) throw new DomainException('Credit exceeds the open balance of '.Money::format($invoice->balance_cents).'. Refund paid amounts instead.');
                if ($tax > $invoice->tax_cents) throw new DomainException('Credited tax cannot exceed the invoiced tax.');
            }

            $prefix = $kind === 'write_off' ? 'WO-' : $business->credit_note_prefix;
            $cn = CreditNote::create([
                'business_id' => $business->id,
                'number' => $this->nextNumber($business, 'credit_note', $prefix),
                'kind' => $kind,
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'issue_date' => $d->toDateString(),
                'reason' => $reason,
                'subtotal_cents' => $sub,
                'tax_cents' => $tax,
                'total_cents' => $sub + $tax,
                'created_by' => $this->ctx->user?->id,
            ]);
            $jl = [['account' => $business->account('ar'), 'credit' => $sub + $tax, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => $cn->number]];
            foreach ($norm as $n) {
                $cn->lines()->create(['business_id' => $business->id] + $n);
                $jl[] = ['account' => $n['income_account_id'], 'debit' => $n['amount_cents'], 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => $cn->number.' · '.$n['description']];
            }
            if ($tax > 0) {
                $jl[] = ['account' => $business->account('sales_tax'), 'debit' => $tax, 'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'description' => $cn->number.' · tax reversal'];
            }
            $entry = $this->ledger->post($business, 'credit_note:'.$cn->id, $d, ($kind === 'write_off' ? 'Write-off ' : 'Credit note ').$cn->number.' for '.$invoice->number, $jl, ['source_type' => 'credit_note', 'source_id' => $cn->id]);
            $cn->journal_entry_id = $entry->id;
            $cn->save();
            PaymentAllocation::create([
                'business_id' => $business->id,
                'credit_note_id' => $cn->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $sub + $tax,
                'applied_date' => $d->toDateString(),
                'journal_entry_id' => $entry->id,
                'created_by' => $this->ctx->user?->id,
            ]);
            $this->refreshBalance($invoice);
            Audit::log($business, $kind === 'write_off' ? 'invoice.written_off' : 'invoice.credited', $invoice, null, ['credit_note' => $cn->number, 'total_cents' => $cn->total_cents], $reason);

            return $cn;
        });
    }
}
