<?php

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Contract;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\Invoice;
use App\Services\Contracts\ContractService;
use App\Services\Notifications\EmailComposer;
use App\Services\Notifications\ReminderPlanner;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    use Heartbeat;

    protected $signature = 'finance:reminders';

    protected $description = 'Queue automatic client reminders (unsigned agreements, unviewed and past-due invoices) per business settings.';

    public function handle(EmailComposer $email, ContractService $contracts, ReminderPlanner $planner): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('reminders', function () use ($email, $contracts, $planner) {
            $stats = ['contract_unsigned' => 0, 'invoice_unviewed' => 0, 'invoice_overdue' => 0, 'failed' => 0];
            foreach (Business::where('status', 'active')->get() as $b) {
                $this->contracts($b, $contracts, $planner, $stats);
                $this->invoices($b, $email, $planner, $stats);
            }

            return $stats;
        });
    }

    private function due(?array $plan, Business $b): bool
    {
        return $plan && $plan['date']->lte($b->localToday()) && ! $plan['paused_until']?->isFuture();
    }

    private function contracts(Business $b, ContractService $svc, ReminderPlanner $planner, array &$stats): void
    {
        $cfg = $b->reminder('contract_unsigned');
        $contracts = Contract::where('business_id', $b->id)
            ->when($cfg['enabled'], fn ($q) => $q->where(fn ($q) => $q->whereNull('auto_reminders')->orWhere('auto_reminders', true)),
                fn ($q) => $q->where('auto_reminders', true))->get()->keyBy('id');
        if ($contracts->isEmpty()) return;
        $versions = ContractVersion::where('business_id', $b->id)->whereIn('contract_id', $contracts->keys())
            ->whereIn('status', ['sent', 'partially_signed'])->get()->keyBy('id');
        $recipients = ContractRecipient::where('business_id', $b->id)->whereIn('contract_version_id', $versions->keys())
            ->where('role', 'client_signer')->whereIn('status', ['sent', 'viewed', 'verified'])->get();
        foreach ($recipients as $r) {
            $v = $versions[$r->contract_version_id];
            if (! $this->due($planner->recipient($r, $v, $contracts[$v->contract_id], $b), $b)) continue;
            try {
                $svc->remind($r, 'Automatic reminder: awaiting signature (every '.$cfg['every_days'].' days)');
                $stats['contract_unsigned']++;
            } catch (DomainException $e) {
                $stats['failed']++;
                report($e);
            }
        }
    }

    private function invoices(Business $b, EmailComposer $email, ReminderPlanner $planner, array &$stats): void
    {
        $anyEnabled = $b->reminder('invoice_unviewed')['enabled'] || $b->reminder('invoice_overdue')['enabled'];
        $invoices = Invoice::with('client.contacts')->where('business_id', $b->id)->where('status', 'issued')
            ->where('balance_cents', '>', 0)
            ->when($anyEnabled, fn ($q) => $q->where(fn ($q) => $q->whereNull('auto_reminders')->orWhere('auto_reminders', true)),
                fn ($q) => $q->where('auto_reminders', true))->get();
        foreach ($invoices as $inv) {
            $plan = $planner->invoice($inv, $b);
            if (! $this->due($plan, $b)) continue;
            if ($plan['type'] === 'invoice_overdue') {
                $this->send($email, $inv, null, 'Automatic reminder: past due (every '.$plan['every'].' days)', 'invoice_overdue', $stats);
            } else {
                $this->send($email, $inv, 'We sent you invoice '.$inv->number.' and wanted to make sure it reached you. You can view it and download a PDF securely below.',
                    'Automatic reminder: invoice not yet viewed (every '.$plan['every'].' days)', 'invoice_unviewed', $stats);
            }
        }
    }

    private function send(EmailComposer $email, Invoice $inv, ?string $message, string $reason, string $stat, array &$stats): void
    {
        // e.g. imported opening balances have no PDF to send.
        if (! $inv->latestDocument()) return;
        $to = $inv->deliveryRecipients();
        if (! $to) return;
        try {
            $email->queueInvoice($inv, $to, 'reminder', $message, $reason);
            $stats[$stat]++;
        } catch (DomainException $e) {
            $stats['failed']++;
            report($e);
        }
    }
}
