<?php

namespace App\Services\Notifications;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\EmailOutbox;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit;
use App\Services\PublicAccess\PublicLinkService;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Facades\Crypt;

/** Builds client-facing emails and queues them in the transactional outbox. */
class EmailComposer
{
    public function __construct(private readonly PublicLinkService $links, private readonly Context $ctx) {}

    public static function fromHeader(Business $b): string
    {
        $name = trim(preg_replace('/[\r\n<>"\\\\,;]+/', ' ', $b->sender_name ?: $b->name()));
        $approved = config('finance.notifications.approved_senders', []);
        $email = $b->sender_email && in_array(strtolower($b->sender_email), array_map('strtolower', $approved), true)
            ? $b->sender_email
            : (string) config('finance.notifications.default_from_email');

        return $name.' <'.$email.'>';
    }

    public static function safeHeader(string $s): string
    {
        return trim(mb_substr(preg_replace('/[\r\n]+/', ' ', $s), 0, 200));
    }

    public function queue(Business $business, string $kind, string $to, string $subject, string $body, array $refs = []): EmailOutbox
    {
        $to = strtolower(trim($to));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
            throw new DomainException('Invalid recipient email address.');
        }

        return EmailOutbox::create(array_merge([
            'business_id' => $business->id,
            'kind' => $kind,
            'to_email' => $to,
            'from_header' => self::fromHeader($business),
            'subject' => self::safeHeader($subject),
            'encrypted_body' => Crypt::encryptString($body),
            'status' => 'queued',
            'created_by' => $this->ctx->user?->id,
            'correlation_id' => $this->ctx->correlationId.'-'.bin2hex(random_bytes(3)),
        ], $refs));
    }

    /** @param list<string> $recipients */
    public function queueInvoice(Invoice $invoice, array $recipients, string $kind = 'invoice', ?string $message = null): int
    {
        $invoice->refresh();
        if (! $invoice->isIssued()) throw new DomainException('Issue the invoice before sending it.');
        $business = $invoice->business;
        $version = $invoice->latestDocument();
        if (! $version) throw new DomainException('Invoice PDF is missing.');
        $recipients = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $recipients))));
        if (! $recipients) throw new DomainException('Add at least one recipient email.');

        $count = 0;
        foreach ($recipients as $to) {
            [$token, $raw] = $this->links->issue($business, 'invoice_view', [
                'invoice_id' => $invoice->id,
                'contract_version_id' => $invoice->contract_version_id,
                'allowed_actions' => ['view', 'pdf', 'report_payment', 'view_contract'],
                'recipient_email' => $to,
            ], $business->invoice_link_ttl_days);
            $url = url('/i/'.$raw);
            $snap = $invoice->client_snapshot ?? [];
            $instr = $invoice->instructions_snapshot ?? [];
            $lines = [];
            $lines[] = 'Hello '.($snap['display_name'] ?? '').',';
            $lines[] = '';
            $lines[] = $message ?: ($kind === 'reminder'
                ? 'This is a friendly reminder that the invoice below has an open balance.'
                : $business->brand('email_intro'));
            $lines[] = '';
            $lines[] = 'Invoice: '.$invoice->number;
            $lines[] = 'Amount due: '.Money::format($invoice->balance_cents);
            $lines[] = 'Due date: '.$invoice->due_date?->format('F j, Y');
            $lines[] = '';
            $lines[] = 'View invoice & pay: '.$url;
            $lines[] = 'Download PDF: '.$url.'?d=pdf';
            $lines[] = '';
            if (! empty($instr['zelle'])) {
                $z = $instr['zelle'];
                $lines[] = 'Pay with Zelle (in your bank\'s app): send to '.($z['recipient_name'] ?? '').' at '.trim(($z['email'] ?? '').' '.($z['phone'] ?? '')).' — memo "'.$invoice->number.'".';
            }
            if (! empty($instr['check'])) {
                $c = $instr['check'];
                $lines[] = 'Pay by check: payable to '.($c['payee'] ?? '').', mailed to '.str_replace("\n", ', ', (string) ($c['address'] ?? '')).' — memo "'.$invoice->number.'".';
            }
            $lines[] = '';
            $lines[] = 'This secure link expires on '.$token->expires_at->format('F j, Y').'.';
            $lines[] = '';
            $lines[] = 'Thank you,';
            $lines[] = $business->name();

            $subject = ($kind === 'reminder' ? 'Reminder: ' : '').'Invoice '.$invoice->number.' from '.$business->name();
            $this->queue($business, $kind, $to, $subject, implode("\n", $lines), [
                'invoice_id' => $invoice->id,
                'invoice_document_version_id' => $version->id,
            ]);
            $count++;
        }
        $invoice->delivery_status = 'queued';
        if ($kind === 'reminder') $invoice->last_reminder_at = now();
        $invoice->save();
        Audit::log($business, 'invoice.'.($kind === 'reminder' ? 'reminder_queued' : 'send_queued'), $invoice, null, ['recipients' => $recipients]);

        return $count;
    }

    public function queueReceipt(Payment $payment): void
    {
        $payment->loadMissing('client.contacts', 'allocations.invoice');
        $to = $payment->client->billingEmail();
        if (! $to || $payment->receipt_emailed_at) return;
        $business = Business::find($payment->business_id);
        $lines = ['Hello '.$payment->client->display_name.',', '', 'We received your payment. Thank you!', ''];
        $lines[] = 'Amount: '.Money::format($payment->amount_cents);
        $lines[] = 'Method: '.(\App\Services\Payments\PaymentService::METHODS[$payment->method] ?? $payment->method);
        $lines[] = 'Date received: '.$payment->received_date->format('F j, Y');
        foreach ($payment->allocations->where('status', 'active') as $a) {
            $lines[] = 'Applied to '.$a->invoice->number.': '.Money::format($a->amount_cents).' — remaining balance '.Money::format($a->invoice->fresh()->balance_cents);
        }
        $unapplied = $payment->unappliedCents();
        if ($unapplied > 0) $lines[] = 'Credit on account: '.Money::format($unapplied);
        $lines[] = '';
        $lines[] = $business->name();
        $this->queue($business, 'receipt', $to, 'Payment received — '.$business->name(), implode("\n", $lines), ['payment_id' => $payment->id]);
        $payment->receipt_emailed_at = now();
        $payment->save();
    }

    public function queueContractInvitation(ContractRecipient $r, string $url, bool $reminder = false): void
    {
        $v = $r->version()->with('contract')->first();
        $business = Business::find($r->business_id);
        $body = implode("\n", [
            'Hello '.$r->name.',', '',
            $business->name().' has sent you "'.$v->title.'" to review and sign electronically.', '',
            'Review & sign: '.$url, '',
            'You will be asked to confirm a one-time code sent to this email address before signing.',
            'If you prefer to sign on paper, reply to '.$business->name().' and they can arrange an alternative.', '',
            'This link expires on '.optional($v->expires_at)->format('F j, Y').'.', '',
            $business->name(),
        ]);
        $this->queue($business, $reminder ? 'contract_reminder' : 'contract_invitation', $r->email,
            ($reminder ? 'Reminder: ' : '').'Please sign: '.$v->title, $body,
            ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    public function queueSigningCode(ContractRecipient $r, string $code): void
    {
        $business = Business::find($r->business_id);
        $body = "Your verification code to sign with {$business->name()} is:\n\n{$code}\n\nIt expires in ".config('finance.signing.otp_ttl_minutes')." minutes. If you did not request this code, ignore this email.";
        $this->queue($business, 'signing_code', $r->email, 'Your signing verification code', $body, ['contract_recipient_id' => $r->id]);
    }

    public function queueContractNotice(ContractRecipient $r, ContractVersion $v, string $kind, ?string $url = null): void
    {
        $business = Business::find($r->business_id);
        $text = match ($kind) {
            'contract_completed' => 'All parties have signed "'.$v->title.'". You can download the signed agreement and its signature certificate here: '.$url,
            'contract_voided' => '"'.$v->title.'" has been withdrawn by '.$business->name().'. No further action is needed.',
            'contract_declined' => '"'.$v->title.'" was declined by a signer. '.$business->name().' will follow up.',
            default => '',
        };
        $subject = match ($kind) {
            'contract_completed' => 'Completed: '.$v->title,
            'contract_voided' => 'Withdrawn: '.$v->title,
            default => 'Update: '.$v->title,
        };
        $this->queue($business, $kind, $r->email, $subject, 'Hello '.$r->name.",\n\n".$text."\n\n".$business->name(), ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    public function queueBusinessInvitation(Invitation $inv, string $url): void
    {
        $business = Business::find($inv->business_id);
        $body = implode("\n", [
            'Hello,', '',
            $inv->inviter->label().' invited you to join '.$business->name().' in McNutt Finance as '.$inv->role->name.'.', '',
            'Accept invitation: '.$url, '',
            'You will sign in with your McNutt Cloud account. This invitation expires on '.$inv->expires_at->format('F j, Y').'.',
        ]);
        $this->queue($business, 'business_invitation', $inv->email, 'Invitation to join '.$business->name(), $body);
    }
}
