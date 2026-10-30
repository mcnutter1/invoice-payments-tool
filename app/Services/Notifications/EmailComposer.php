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

/** Builds branded HTML client emails (with open/click tracking) and queues them in the transactional outbox. */
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

    public static function brand(Business $b): array
    {
        return [
            'name' => $b->name(),
            'accent' => $b->brand('accent'),
            'logo' => $b->logo_document_id ? url('/brand/'.$b->ulid.'/logo') : null,
            'contact' => implode(' · ', array_filter([$b->public_email, $b->phone])),
        ];
    }

    /**
     * Render and queue one email. Buttons with a 'track' action get click tracking; every email gets an open pixel.
     *
     * @param array<string,mixed> $email view data for emails.message
     */
    public function queue(Business $business, string $kind, string $to, string $subject, array $email, array $refs = []): EmailOutbox
    {
        $to = strtolower(trim($to));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
            throw new DomainException('Invalid recipient email address.');
        }
        $subject = self::safeHeader($subject);
        [$raw, $hash] = EmailTracker::newToken();
        $email['buttons'] = array_map(fn ($b) => isset($b['track'])
            ? ['url' => EmailTracker::trackedUrl($b['url'], $raw, $b['track'])] + $b
            : $b, $email['buttons'] ?? []);
        $html = view('emails.message', $email + [
            'subject' => $subject,
            'brand' => self::brand($business),
            'pixel' => url('/t/o/'.$raw.'.gif'),
        ])->render();

        return EmailOutbox::create(array_merge([
            'business_id' => $business->id,
            'kind' => $kind,
            'to_email' => $to,
            'from_header' => self::fromHeader($business),
            'subject' => $subject,
            'encrypted_body' => Crypt::encryptString($html),
            'tracking_hash' => $hash,
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

        $snap = $invoice->client_snapshot ?? [];
        $instr = $invoice->instructions_snapshot ?? [];
        $payable = $invoice->balance_cents > 0;
        $overdue = $invoice->isOverdue();
        $sections = [];
        if ($payable && ! empty($instr['zelle'])) {
            $z = $instr['zelle'];
            $sections[] = ['title' => 'Pay with Zelle', 'lines' => array_filter([
                'Send to '.($z['recipient_name'] ?? '').' using your bank’s app.',
                trim(implode(' · ', array_filter([$z['email'] ?? null, $z['phone'] ?? null]))) ?: null,
                'Memo: Invoice '.$invoice->number,
            ])];
        }
        if ($payable && ! empty($instr['check'])) {
            $c = $instr['check'];
            $sections[] = ['title' => 'Pay by check', 'lines' => [
                'Payable to '.($c['payee'] ?? ''),
                'Mail to '.str_replace("\n", ', ', (string) ($c['address'] ?? '')),
                'Memo: Invoice '.$invoice->number,
            ]];
        }
        $intro = $message ?: match (true) {
            $kind === 'reminder' && $overdue => 'This is a friendly reminder that the invoice below is past due. If you’ve already sent payment, thank you — you can let us know from the invoice page.',
            $kind === 'reminder' => 'This is a friendly reminder that the invoice below has an open balance.',
            default => $business->brand('email_intro') ?: 'Thank you for your business. Your invoice is ready.',
        };

        $count = 0;
        foreach ($recipients as $to) {
            [$token, $raw] = $this->links->issue($business, 'invoice_view', [
                'invoice_id' => $invoice->id,
                'contract_version_id' => $invoice->contract_version_id,
                'allowed_actions' => ['view', 'pdf', 'report_payment', 'view_contract'],
                'recipient_email' => $to,
            ], $business->invoice_link_ttl_days);
            $url = url('/i/'.$raw);
            $buttons = $payable
                ? [['label' => 'Pay invoice', 'url' => $url, 'track' => 'pay'], ['label' => 'View invoice', 'url' => $url, 'track' => 'view', 'style' => 'secondary'], ['label' => 'Download PDF', 'url' => $url.'?d=pdf', 'track' => 'pdf', 'style' => 'secondary']]
                : [['label' => 'View invoice', 'url' => $url, 'track' => 'view'], ['label' => 'Download PDF', 'url' => $url.'?d=pdf', 'track' => 'pdf', 'style' => 'secondary']];

            $subject = ($kind === 'reminder' ? ($overdue ? 'Past due: ' : 'Reminder: ') : '').'Invoice '.$invoice->number.' from '.$business->name();
            $this->queue($business, $kind, $to, $subject, [
                'tag' => $kind === 'reminder' ? 'Payment reminder' : 'Invoice',
                'preheader' => 'Invoice '.$invoice->number.' · '.Money::format($invoice->balance_cents).($invoice->due_date ? ' due '.$invoice->due_date->format('M j, Y') : ''),
                'greeting' => 'Hello '.($snap['display_name'] ?? 'there').',',
                'paragraphs' => [$intro],
                'highlight' => [
                    'label' => $payable ? ($invoice->paid_cents > 0 ? 'Remaining balance' : 'Amount due') : 'Balance',
                    'value' => Money::format(max(0, $invoice->balance_cents)),
                    'sub' => $payable && $invoice->due_date ? 'Due '.$invoice->due_date->format('F j, Y').($overdue ? ' · past due' : '') : ($payable ? null : 'Paid in full — thank you!'),
                    'tone' => $overdue ? 'alert' : null,
                ],
                'details' => array_values(array_filter([
                    ['Invoice', $invoice->number],
                    ['Issued', $invoice->issue_date?->format('F j, Y')],
                    $invoice->po_number ? ['PO', $invoice->po_number] : null,
                    ['Invoice total', Money::format($invoice->total_cents)],
                ], fn ($r) => $r && $r[1])),
                'buttons' => $buttons,
                'sections' => $sections,
                'notes' => ['This secure link is personal to you and expires on '.$token->expires_at->format('F j, Y').'.'],
                'signoff' => 'Thank you,',
            ], [
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
        $details = [
            ['Method', \App\Services\Payments\PaymentService::METHODS[$payment->method] ?? $payment->method],
            ['Date received', $payment->received_date->format('F j, Y')],
        ];
        foreach ($payment->allocations->where('status', 'active') as $a) {
            $details[] = ['Applied to '.$a->invoice->number, Money::format($a->amount_cents)];
            $details[] = [$a->invoice->number.' balance', Money::format($a->invoice->fresh()->balance_cents)];
        }
        if (($unapplied = $payment->unappliedCents()) > 0) $details[] = ['Credit on account', Money::format($unapplied)];
        $this->queue($business, 'receipt', $to, 'Payment received — '.$business->name(), [
            'tag' => 'Receipt',
            'preheader' => 'We received your payment of '.Money::format($payment->amount_cents).'.',
            'greeting' => 'Hello '.$payment->client->display_name.',',
            'paragraphs' => ['We received your payment. Thank you!'],
            'highlight' => ['label' => 'Payment received', 'value' => Money::format($payment->amount_cents)],
            'details' => $details,
            'signoff' => 'With thanks,',
        ], ['payment_id' => $payment->id]);
        $payment->receipt_emailed_at = now();
        $payment->save();
    }

    public function queueContractInvitation(ContractRecipient $r, string $url, bool $reminder = false): void
    {
        $v = $r->version()->with('contract')->first();
        $business = Business::find($r->business_id);
        $this->queue($business, $reminder ? 'contract_reminder' : 'contract_invitation', $r->email,
            ($reminder ? 'Reminder: ' : '').'Please sign: '.$v->title, [
                'tag' => 'Signature requested',
                'preheader' => $business->name().' sent you “'.$v->title.'” to review and sign.',
                'heading' => $v->title,
                'greeting' => 'Hello '.$r->name.',',
                'paragraphs' => [$business->name().' has sent you this agreement to review and sign electronically.'],
                'buttons' => [['label' => 'Review & sign', 'url' => $url, 'track' => 'sign']],
                'notes' => array_filter([
                    'You’ll be asked to confirm a one-time code sent to this email address before signing.',
                    'Prefer to sign on paper? Reply to '.$business->name().' and they can arrange an alternative.',
                    $v->expires_at ? 'This link expires on '.$v->expires_at->format('F j, Y').'.' : null,
                ]),
            ], ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    public function queueSigningCode(ContractRecipient $r, string $code): void
    {
        $business = Business::find($r->business_id);
        $this->queue($business, 'signing_code', $r->email, 'Your signing verification code', [
            'tag' => 'Verification code',
            'preheader' => 'Your code is '.$code,
            'paragraphs' => ['Use this code to verify your email and continue signing with '.$business->name().'.'],
            'code' => $code,
            'notes' => ['It expires in '.config('finance.signing.otp_ttl_minutes').' minutes. If you didn’t request this code, you can ignore this email.'],
        ], ['contract_recipient_id' => $r->id]);
    }

    public function queueContractNotice(ContractRecipient $r, ContractVersion $v, string $kind, ?string $url = null): void
    {
        $business = Business::find($r->business_id);
        [$subject, $text] = match ($kind) {
            'contract_completed' => ['Completed: '.$v->title, 'All parties have signed “'.$v->title.'”. You can download the signed agreement and its signature certificate below.'],
            'contract_voided' => ['Withdrawn: '.$v->title, '“'.$v->title.'” has been withdrawn by '.$business->name().'. No further action is needed.'],
            'contract_declined' => ['Update: '.$v->title, '“'.$v->title.'” was declined by a signer. '.$business->name().' will follow up.'],
            default => ['Update: '.$v->title, ''],
        };
        $this->queue($business, $kind, $r->email, $subject, [
            'tag' => 'Agreement',
            'heading' => $v->title,
            'greeting' => 'Hello '.$r->name.',',
            'paragraphs' => [$text],
            'buttons' => $url ? [['label' => 'View signed agreement', 'url' => $url, 'track' => 'agreement']] : [],
        ], ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    public function queueBusinessInvitation(Invitation $inv, string $url): void
    {
        $business = Business::find($inv->business_id);
        $this->queue($business, 'business_invitation', $inv->email, 'Invitation to join '.$business->name(), [
            'tag' => 'Invitation',
            'greeting' => 'Hello,',
            'paragraphs' => [$inv->inviter->label().' invited you to join '.$business->name().' in McNutt Finance as '.$inv->role->name.'.'],
            'buttons' => [['label' => 'Accept invitation', 'url' => $url]],
            'notes' => ['You’ll sign in with your McNutt Cloud account. This invitation expires on '.$inv->expires_at->format('F j, Y').'.'],
        ]);
    }

    public function queueTest(Business $business, string $to): EmailOutbox
    {
        return $this->queue($business, 'test', $to, 'Test email from '.$business->name(), [
            'tag' => 'Test',
            'heading' => 'Email delivery is working',
            'paragraphs' => ['This is a test message from McNutt Finance for '.$business->name().'. If you can read it, email delivery and branding are configured.'],
            'details' => [['Sender', self::fromHeader($business)], ['Sent', now()->timezone($business->timezone)->format('M j, Y g:i a T')]],
        ]);
    }
}
