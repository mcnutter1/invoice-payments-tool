<?php

namespace App\Services\Notifications;

use App\Models\Business;
use App\Models\Contract;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use Illuminate\Support\Carbon;

/** Works out when the next automatic reminder is due; shared by the reminder job and the activity timelines. */
class ReminderPlanner
{
    /** Client actions showing a person actually looked at the invoice (email opens and pre-scans are excluded). */
    public const ENGAGED = ['invoice_viewed', 'pdf_downloaded', 'pay_clicked', 'payment_reported', 'payment_details_copied', 'remittance_downloaded'];

    /** @return array{date: Carbon, type: string, every: int, paused_until: ?Carbon}|null  date is the business-local due date */
    public function invoice(Invoice $inv, Business $b): ?array
    {
        if ($inv->status !== 'issued' || $inv->balance_cents <= 0 || ! $inv->autoRemindersOn($b)) return null;
        $forced = $inv->auto_reminders === true;
        $u = $b->reminder('invoice_unviewed');
        $o = $b->reminder('invoice_overdue');
        $sent = $this->localDate($b, EmailOutbox::where('business_id', $b->id)->where('invoice_id', $inv->id)
            ->whereIn('kind', ['invoice', 'reminder'])->whereNotIn('status', ['cancelled', 'failed'])->max('created_at'));
        $due = $inv->due_date ? Carbon::parse($inv->due_date->toDateString()) : null;

        $uDate = ($forced || $u['enabled']) && $sent && ! $this->viewed($inv) ? $sent->copy()->addDays($u['every_days']) : null;
        $oDate = ($forced || $o['enabled']) && $due
            ? max($due->copy()->addDays($o['every_days']), $sent ? $sent->copy()->addDays($o['every_days']) : $due->copy())
            : null;
        // Before the due date the unviewed reminder applies; after it, the past-due one takes over.
        $plan = match (true) {
            $uDate && (! $oDate || $uDate->lte($due)) => ['date' => $uDate, 'type' => 'invoice_unviewed', 'every' => $u['every_days']],
            (bool) $oDate => ['date' => $oDate, 'type' => 'invoice_overdue', 'every' => $o['every_days']],
            default => null,
        };
        if (! $plan) return null;

        $claim = PaymentClaim::where('invoice_id', $inv->id)->where('status', 'reported')
            ->where('created_at', '>', now()->subDays($b->claim_review_days))->latest('created_at')->value('created_at');
        $plan['paused_until'] = $claim ? Carbon::parse($claim)->addDays($b->claim_review_days) : null;

        return $plan;
    }

    /** @return array{date: Carbon, type: string, every: int, paused_until: null}|null */
    public function recipient(ContractRecipient $r, ContractVersion $v, Contract $c, Business $b): ?array
    {
        if (! $c->autoRemindersOn($b) || $r->role !== 'client_signer' || ! in_array($r->status, ['sent', 'viewed', 'verified'], true)
            || ! in_array($v->status, ['sent', 'partially_signed'], true) || ($v->expires_at && $v->expires_at->lte(now()))) {
            return null;
        }
        $last = EmailOutbox::where('business_id', $b->id)->where('contract_recipient_id', $r->id)
            ->whereIn('kind', ['contract_invitation', 'contract_reminder'])->max('created_at') ?? $v->sent_at;
        if (! $last) return null;
        $every = $b->reminder('contract_unsigned')['every_days'];

        return ['date' => $this->localDate($b, $last)->addDays($every), 'type' => 'contract_unsigned', 'every' => $every, 'paused_until' => null];
    }

    /** The scheduled run (finance:reminders) that will send a reminder planned for $plan['date']. */
    public function runAt(Business $b, array $plan): Carbon
    {
        $tz = $b->timezone ?: 'America/New_York';
        [$h, $m] = array_map('intval', explode(':', (string) config('finance.reminders.run_at', '14:00')));
        $date = $plan['date']->toDateString();
        $run = Carbon::parse($date, 'UTC')->subDay()->setTime($h, $m);
        while ($run->copy()->timezone($tz)->toDateString() < $date) $run->addDay();
        $floor = max(now(), $plan['paused_until'] ?? now());
        while ($run->lte($floor)) $run->addDay();

        return $run;
    }

    private function viewed(Invoice $inv): bool
    {
        return DocumentAccessEvent::where('business_id', $inv->business_id)->where('invoice_id', $inv->id)
            ->where('probable_automation', false)->whereIn('event', self::ENGAGED)->exists();
    }

    private function localDate(Business $b, mixed $at): ?Carbon
    {
        return $at ? Carbon::parse(Carbon::parse($at)->timezone($b->timezone ?: 'America/New_York')->toDateString()) : null;
    }
}
