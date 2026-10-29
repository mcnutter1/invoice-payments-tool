<?php

namespace App\Services\Notifications;

use App\Models\Business;
use App\Models\ContractVersion;
use App\Models\EmailAttempt;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\PublicToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Bounded outbox worker invoked by cron. */
class OutboxProcessor
{
    public function __construct(private readonly NotificationGateway $gateway) {}

    public function run(int $limit, float $deadline): array
    {
        $stats = ['accepted' => 0, 'retry' => 0, 'failed' => 0, 'ambiguous' => 0, 'cancelled' => 0];

        // Workers that died mid-send leave rows in 'sending': outcome unknown, never auto-resend.
        EmailOutbox::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'delivery_unknown', 'needs_review' => true, 'last_error' => 'worker_interrupted']);

        $ids = EmailOutbox::where('status', 'queued')->where('available_at', '<=', now())
            ->orderBy('available_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            if (microtime(true) > $deadline) break;
            $claimed = EmailOutbox::where('id', $id)->where('status', 'queued')
                ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if (! $claimed) continue;
            $row = EmailOutbox::find($id);
            $stats[$this->deliver($row)]++;
        }

        // Purge raw delivery material that is no longer needed.
        EmailOutbox::whereIn('status', ['failed', 'cancelled', 'delivery_unknown'])->whereNull('purged_at')
            ->where('updated_at', '<', now()->subDays(14))
            ->update(['encrypted_body' => null, 'purged_at' => now()]);

        return $stats;
    }

    private function deliver(EmailOutbox $row): string
    {
        if ($reason = $this->cancelReason($row)) {
            $row->update(['status' => 'cancelled', 'last_error' => $reason, 'encrypted_body' => null, 'purged_at' => now()]);

            return 'cancelled';
        }
        $attempt = EmailAttempt::create([
            'email_outbox_id' => $row->id,
            'attempt_no' => $row->attempts,
            'started_at' => now(),
            'result' => 'started',
        ]);
        try {
            $body = Crypt::decryptString((string) $row->encrypted_body);
            $result = $this->gateway->send(new OutgoingMessage($row->to_email, $row->from_header, $row->subject, $body, $row->correlation_id));
        } catch (\Throwable $e) {
            Log::error('outbox.send_exception', ['outbox' => $row->ulid, 'error' => class_basename($e)]);
            $result = new SendResult('ambiguous', null, null, 'exception_'.class_basename($e));
        }
        $attempt->update(['finished_at' => now(), 'http_status' => $result->httpStatus, 'result' => $result->status, 'error' => $result->error]);

        switch ($result->status) {
            case 'accepted':
                $row->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'provider_message_id' => $result->messageId,
                    'last_error' => null,
                    'encrypted_body' => null,
                    'purged_at' => now(),
                ]);
                $this->afterAccepted($row);

                return 'accepted';
            case 'transient':
                if ($row->attempts >= $row->max_attempts) {
                    $row->update(['status' => 'failed', 'last_error' => $result->error, 'needs_review' => true]);
                    $this->markInvoice($row, 'failed');

                    return 'failed';
                }
                $delay = min(3600, (int) (60 * (2 ** ($row->attempts - 1)))) + random_int(0, 30);
                $row->update(['status' => 'queued', 'available_at' => now()->addSeconds($delay), 'last_error' => $result->error]);

                return 'retry';
            case 'ambiguous':
                $row->update(['status' => 'delivery_unknown', 'needs_review' => true, 'last_error' => $result->error]);
                $this->markInvoice($row, 'unknown');

                return 'ambiguous';
            default:
                $row->update(['status' => 'failed', 'needs_review' => true, 'last_error' => $result->error]);
                $this->markInvoice($row, 'failed');

                return 'failed';
        }
    }

    /** Background recheck of document state, link revocation and business state before sending. */
    private function cancelReason(EmailOutbox $row): ?string
    {
        $business = Business::find($row->business_id);
        if (! $business || ! $business->isActive()) return 'business_inactive';
        if ($row->invoice_id) {
            $inv = Invoice::find($row->invoice_id);
            if (! $inv || $inv->status !== 'issued') return 'invoice_not_issued';
            if (in_array($row->kind, ['invoice', 'reminder'], true)
                && ! PublicToken::where('invoice_id', $inv->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                return 'link_revoked';
            }
        }
        if ($row->contract_version_id && in_array($row->kind, ['contract_invitation', 'contract_reminder', 'signing_code'], true)) {
            $v = ContractVersion::find($row->contract_version_id);
            if (! $v || ! in_array($v->status, ['sent', 'partially_signed'], true)) return 'contract_not_open';
        }

        return null;
    }

    private function afterAccepted(EmailOutbox $row): void
    {
        $this->markInvoice($row, 'sent');
    }

    private function markInvoice(EmailOutbox $row, string $status): void
    {
        if (! $row->invoice_id || ! in_array($row->kind, ['invoice', 'reminder'], true)) return;
        $inv = Invoice::find($row->invoice_id);
        if (! $inv) return;
        // "sent" means accepted by the mail service, not delivered.
        if ($status === 'sent' || $inv->delivery_status !== 'sent') {
            $inv->delivery_status = $status;
            $inv->save();
        }
    }
}
