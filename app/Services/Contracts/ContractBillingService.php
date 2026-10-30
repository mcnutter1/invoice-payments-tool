<?php

namespace App\Services\Contracts;

use App\Models\Business;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\ContractVersion;
use App\Models\Invoice;
use App\Models\RecurringSchedule;
use App\Services\Audit;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use App\Services\Recurring\RecurringService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Turns an executed agreement's billing plan (milestones + monthly fee) into invoices and a recurring schedule. */
class ContractBillingService
{
    public const MODES = [
        'draft' => 'Create drafts for my review',
        'issue' => 'Issue automatically (don’t email)',
        'issue_send' => 'Issue and email automatically',
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly RecurringService $recurring,
        private readonly EmailComposer $email,
    ) {}

    /** Billing must never undo a completed signature: failures are logged and audited for follow-up. */
    public function runSafely(int $contractId): void
    {
        $contract = Contract::find($contractId);
        if (! $contract) return;
        try {
            $this->onExecuted($contract);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('contract.billing_failed', ['contract' => $contractId, 'error' => $e->getMessage()]);
            Audit::log($contract->business_id, 'contract.billing_failed', $contract, null, ['error' => mb_substr($e->getMessage(), 0, 300)], null, 'system');
        }
    }

    /** Idempotent: safe to call on signing completion, external upload, or manually. @return array{milestones:int, schedule:?int} */
    public function onExecuted(Contract $contract): array
    {
        $out = ['milestones' => 0, 'schedule' => null];
        $contract->refresh();
        if (! $contract->billing_auto || ! $contract->isExecuted()) return $out;
        $versionId = $this->executedVersionId($contract);

        foreach (ContractMilestone::where('contract_id', $contract->id)->where('status', 'pending')->whereNull('invoice_id')->orderBy('due_date')->get() as $m) {
            $this->milestoneInvoice($contract, $m, $versionId);
            $out['milestones']++;
        }
        if ($contract->billing_monthly_cents > 0) {
            $out['schedule'] = $this->monthlySchedule($contract)?->id;
        }

        return $out;
    }

    public function milestoneInvoice(Contract $contract, ContractMilestone $m, ?int $versionId): Invoice
    {
        return DB::transaction(function () use ($contract, $m, $versionId) {
            $m = ContractMilestone::lockForUpdate()->findOrFail($m->id);
            if ($m->invoice_id) return Invoice::findOrFail($m->invoice_id);
            $business = Business::findOrFail($contract->business_id);
            $today = $business->localToday();
            $invoice = $this->invoices->saveDraft($business, null, [
                'client_id' => $contract->client_id,
                'issue_date' => $m->due_date && $m->due_date->gt($today) ? $m->due_date->toDateString() : null,
                'contract_version_id' => $versionId,
                'lines' => [[
                    'name' => $m->name,
                    'description' => trim(($m->description ? $m->description."\n" : '').'Milestone payment per agreement '.$contract->number.' · '.$contract->title),
                    'quantity' => '1',
                    'unit_price' => Money::toDecimalString($m->amount_cents),
                    'contract_milestone_id' => $m->id,
                    'tax_rate_id' => null,
                ]],
            ]);
            $m->update(['status' => 'scheduled', 'invoice_id' => $invoice->id]);
            Audit::log($business, 'contract.milestone_invoice_created', $contract, null, ['milestone' => $m->name, 'invoice_id' => $invoice->id], null, 'system');
            if ($contract->billing_mode !== 'draft' && (! $m->due_date || $m->due_date->lte($today))) {
                $invoice = $this->issue($contract, $invoice);
            }

            return $invoice;
        });
    }

    private function monthlySchedule(Contract $contract): ?RecurringSchedule
    {
        $existing = RecurringSchedule::where('business_id', $contract->business_id)->where('contract_id', $contract->id)->where('origin', 'contract')->first();
        if ($existing) return $existing;
        $business = Business::findOrFail($contract->business_id);
        $start = $contract->billing_start_date ?? $contract->term_start_date ?? $contract->effective_date ?? $business->localToday();
        $input = $contract->currentVersion?->snapshot['input'] ?? [];
        $schedule = $this->recurring->save($business, null, [
            'client_id' => $contract->client_id,
            'contract_id' => $contract->id,
            'name' => $contract->title.' — monthly fee',
            'cadence' => 'month',
            'interval' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $contract->term_end_date?->toDateString(),
            'anchor_day' => $start->day,
            'terms_days' => $input['payment_terms_days'] ?? $business->default_terms_days,
            'auto_issue' => $contract->billing_mode !== 'draft',
            'auto_send' => $contract->billing_mode === 'issue_send',
            'require_signed_contract' => true,
            'lines' => [[
                'name' => $contract->billing_monthly_description ?: 'Monthly fee',
                'description' => 'Monthly payment per agreement '.$contract->number.' · '.$contract->title,
                'quantity' => '1',
                'unit_price' => Money::toDecimalString($contract->billing_monthly_cents),
            ]],
        ]);
        $schedule->update(['origin' => 'contract']);
        Audit::log($business, 'contract.recurring_created', $contract, null, ['schedule_id' => $schedule->id], null, 'system');

        return $schedule;
    }

    /** Issue (and email) milestone drafts whose date has arrived. Run by the scheduler. */
    public function issueDueMilestones(): int
    {
        $n = 0;
        $due = ContractMilestone::where('status', 'scheduled')->whereNotNull('invoice_id')->whereDate('due_date', '<=', now()->toDateString())->limit(100)->get();
        foreach ($due as $m) {
            $contract = Contract::find($m->contract_id);
            $invoice = Invoice::find($m->invoice_id);
            if (! $contract || ! $invoice || ! $invoice->isDraft() || $contract->billing_mode === 'draft' || ! $contract->isExecuted() || $contract->terminated_at) continue;
            if ($m->due_date->gt(Business::find($contract->business_id)->localToday())) continue;
            $this->issue($contract, $invoice);
            $n++;
        }

        return $n;
    }

    private function issue(Contract $contract, Invoice $invoice): Invoice
    {
        $invoice = $this->invoices->issue($invoice);
        if ($contract->billing_mode === 'issue_send') {
            $invoice->loadMissing('client.contacts');
            $to = $invoice->recipients ?: array_filter([$invoice->client->billingEmail()]);
            if ($to) $this->email->queueInvoice($invoice, $to);
        }

        return $invoice;
    }

    private function executedVersionId(Contract $contract): ?int
    {
        $current = $contract->current_version_id ? ContractVersion::find($contract->current_version_id) : null;
        if ($current && $current->status === 'completed') return $current->id;

        return ContractVersion::where('contract_id', $contract->id)->where('status', 'completed')->latest('version')->value('id')
            ?? $contract->current_version_id; // externally signed agreements have no in-app completed version
    }
}
