<?php

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Contract;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Services\Contracts\ContractService;
use App\Services\Notifications\EmailComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendReminders extends Command
{
    use Heartbeat;

    protected $signature = 'finance:reminders';

    protected $description = 'Queue automatic client reminders (unsigned agreements, unviewed and past-due invoices) per business settings.';

    /** Client actions showing a person actually looked at the invoice (email-open pixels are excluded). */
    private const ENGAGED = ['invoice_viewed', 'pdf_downloaded', 'pay_clicked', 'payment_reported', 'payment_details_copied', 'remittance_downloaded'];

    public function handle(EmailComposer $email, ContractService $contracts): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('reminders', function () use ($email, $contracts) {
            $stats = ['contract_unsigned' => 0, 'invoice_unviewed' => 0, 'invoice_overdue' => 0, 'failed' => 0];
            foreach (Business::where('status', 'active')->get() as $b) {
                $this->contracts($b, $contracts, $stats);
                $this->invoices($b, $email, $stats);
            }

            return $stats;
        });
    }

    /** Whole days in the business's time zone since $at, or null if it never happened. */
    private function daysSince(Business $b, mixed $at): ?int
    {
        if (! $at) return null;
        $local = Carbon::parse($at)->timezone($b->timezone ?: 'America/New_York')->toDateString();

        return (int) Carbon::parse($local)->diffInDays($b->localToday());
    }

    private function contracts(Business $b, ContractService $svc, array &$stats): void
    {
        $cfg = $b->reminder('contract_unsigned');
        $contractIds = Contract::where('business_id', $b->id)
            ->when($cfg['enabled'], fn ($q) => $q->where(fn ($q) => $q->whereNull('auto_reminders')->orWhere('auto_reminders', true)),
                fn ($q) => $q->where('auto_reminders', true))->pluck('id');
        if ($contractIds->isEmpty()) return;
        $versions = ContractVersion::where('business_id', $b->id)->whereIn('contract_id', $contractIds)->whereIn('status', ['sent', 'partially_signed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('sent_at', 'id');
        $recipients = ContractRecipient::where('business_id', $b->id)->whereIn('contract_version_id', $versions->keys())
            ->where('role', 'client_signer')->whereIn('status', ['sent', 'viewed', 'verified'])->get();
        foreach ($recipients as $r) {
            $last = EmailOutbox::where('business_id', $b->id)->where('contract_recipient_id', $r->id)
                ->whereIn('kind', ['contract_invitation', 'contract_reminder'])->max('created_at');
            // Agreements sent before outbox tracking fall back to the version's send time.
            $days = $this->daysSince($b, $last ?? $versions[$r->contract_version_id]);
            if ($days === null || $days < $cfg['every_days']) continue;
            try {
                $svc->remind($r, 'Automatic reminder: awaiting signature (every '.$cfg['every_days'].' days)');
                $stats['contract_unsigned']++;
            } catch (DomainException $e) {
                $stats['failed']++;
                report($e);
            }
        }
    }

    private function invoices(Business $b, EmailComposer $email, array &$stats): void
    {
        $unviewed = $b->reminder('invoice_unviewed');
        $overdue = $b->reminder('invoice_overdue');
        $anyEnabled = $unviewed['enabled'] || $overdue['enabled'];
        $today = $b->localToday();
        $invoices = Invoice::with('client.contacts')->where('business_id', $b->id)->where('status', 'issued')
            ->where('balance_cents', '>', 0)
            ->when($anyEnabled, fn ($q) => $q->where(fn ($q) => $q->whereNull('auto_reminders')->orWhere('auto_reminders', true)),
                fn ($q) => $q->where('auto_reminders', true))->get();
        foreach ($invoices as $inv) {
            // Turned on for this invoice: both reminder types apply, at the business intervals.
            $forced = $inv->auto_reminders === true;
            // A recent client payment report pauses reminders during the review window.
            $recentClaim = PaymentClaim::where('invoice_id', $inv->id)->where('status', 'reported')
                ->where('created_at', '>', now()->subDays($b->claim_review_days))->exists();
            if ($recentClaim) continue;
            $lastSent = EmailOutbox::where('business_id', $b->id)->where('invoice_id', $inv->id)->whereIn('kind', ['invoice', 'reminder'])
                ->whereNotIn('status', ['cancelled', 'failed'])->max('created_at');
            $sinceSent = $this->daysSince($b, $lastSent);

            if (($forced || $overdue['enabled']) && $inv->due_date && $inv->due_date->lt($today)) {
                $sinceDue = (int) $inv->due_date->diffInDays($today);
                if (min($sinceSent ?? PHP_INT_MAX, $sinceDue) < $overdue['every_days']) continue;
                $this->send($email, $inv, null, 'Automatic reminder: past due (every '.$overdue['every_days'].' days)', 'invoice_overdue', $stats);
            } elseif (($forced || $unviewed['enabled']) && $sinceSent !== null && $sinceSent >= $unviewed['every_days']) {
                $viewed = DocumentAccessEvent::where('business_id', $b->id)->where('invoice_id', $inv->id)
                    ->where('probable_automation', false)->whereIn('event', self::ENGAGED)->exists();
                if ($viewed) continue;
                $this->send($email, $inv, 'We sent you invoice '.$inv->number.' and wanted to make sure it reached you. You can view it and download a PDF securely below.',
                    'Automatic reminder: invoice not yet viewed (every '.$unviewed['every_days'].' days)', 'invoice_unviewed', $stats);
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
