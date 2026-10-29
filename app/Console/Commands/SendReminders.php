<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Services\Notifications\EmailComposer;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    use Heartbeat;

    protected $signature = 'finance:reminders';

    protected $description = 'Queue overdue-invoice reminders per business reminder settings.';

    public function handle(EmailComposer $email): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('reminders', function () use ($email) {
            $queued = 0;
            foreach (Business::where('status', 'active')->get() as $b) {
                $cfg = $b->reminder_settings ?? [];
                if (empty($cfg['enabled'])) continue;
                $days = array_map('intval', $cfg['days'] ?? [3, 14, 30]);
                $today = $b->localToday();
                $invoices = Invoice::with('client.contacts')->where('business_id', $b->id)->where('status', 'issued')
                    ->where('balance_cents', '>', 0)->whereDate('due_date', '<', $today)->get();
                foreach ($invoices as $inv) {
                    $overdue = (int) $inv->due_date->diffInDays($today);
                    if (! in_array($overdue, $days, true)) continue;
                    if ($inv->last_reminder_at && $inv->last_reminder_at->gt(now()->subHours(20))) continue;
                    // A recent client payment report pauses ordinary reminders during the review window.
                    $recentClaim = PaymentClaim::where('invoice_id', $inv->id)->where('status', 'reported')
                        ->where('created_at', '>', now()->subDays($b->claim_review_days))->exists();
                    if ($recentClaim) continue;
                    $to = $inv->recipients ?: array_filter([$inv->client->billingEmail()]);
                    if (! $to) continue;
                    $email->queueInvoice($inv, $to, 'reminder');
                    $queued++;
                }
            }

            return ['reminders_queued' => $queued];
        });
    }
}
