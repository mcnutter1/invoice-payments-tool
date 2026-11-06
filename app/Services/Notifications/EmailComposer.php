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
        $name = trim(preg_replace('/[\r\n<>"\\\\,;@]+/', ' ', $b->sender_name ?: $b->name()));
        $approved = config('finance.notifications.approved_senders', []);
        $own = $b->sender_email && in_array(strtolower($b->sender_email), array_map('strtolower', $approved), true);
        // A business name on a shared platform address looks like display-name spoofing; "via" makes the relationship explicit.
        if (! $own) $name .= ' via McNutt Finance';

        return $name.' <'.($own ? $b->sender_email : (string) config('finance.notifications.default_from_email')).'>';
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
            'address' => implode(', ', $b->addressLines()),
        ];
    }

    public static function contactSuffix(Business $b): string
    {
        $c = array_filter([$b->public_email, $b->phone]);

        return $c ? ' at '.implode(' or ', $c) : '';
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
    public function queueInvoice(Invoice $invoice, array $recipients, string $kind = 'invoice', ?string $message = null, ?string $auditReason = null): int
    {
        $invoice->refresh();
        if (! $invoice->isIssued()) throw new DomainException('Issue the invoice before sending it.');
        $business = $invoice->business;
        $version = $invoice->latestDocument();
        if (! $version) throw new DomainException('Invoice PDF is missing.');
        $recipients = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $recipients))));
        if (! $recipients) throw new DomainException('Add at least one recipient email.');

        $snap = $invoice->client_snapshot ?? [];
        $payable = $invoice->balance_cents > 0;
        $overdue = $invoice->isOverdue();
        // Payment details (Zelle handles, mailing addresses) stay on the invoice page and PDF: money-transfer
        // instructions inside an email are the main signal filters use for invoice fraud.
        $intro = $message ?: match (true) {
            $kind === 'reminder' && $overdue => 'Our records show invoice '.$invoice->number.' still has an open balance. If you’ve already paid or mailed a check, thank you — you can let us know from the invoice page so we can update our records.',
            $kind === 'reminder' => 'A friendly reminder that invoice '.$invoice->number.' has an open balance.',
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
            $buttons = [['label' => 'View invoice', 'url' => $url, 'track' => 'view'], ['label' => 'Download PDF', 'url' => $url.'?d=pdf', 'track' => 'pdf']];
            if ($payable && $kind === 'reminder') {
                $buttons[] = ['label' => 'I’ve paid / mailed a check', 'url' => $url, 'track' => 'report'];
            }
            $subject = 'Invoice '.$invoice->number.' from '.$business->name().($kind === 'reminder' ? ' (reminder)' : '');
            $this->queue($business, $kind, $to, $subject, [
                'tag' => $kind === 'reminder' ? 'Reminder' : 'Invoice',
                'greeting' => 'Hello '.($snap['display_name'] ?? 'there').',',
                'paragraphs' => array_values(array_filter([$intro, $payable ? 'Payment options are listed on the invoice.' : null])),
                'highlight' => [
                    'label' => $payable ? ($invoice->paid_cents > 0 ? 'Remaining balance' : 'Amount due') : 'Balance',
                    'value' => Money::format(max(0, $invoice->balance_cents)),
                    'sub' => $payable && $invoice->due_date ? 'Due '.$invoice->due_date->format('F j, Y') : ($payable ? null : 'Paid in full — thank you!'),
                ],
                'details' => array_values(array_filter([
                    ['Invoice', $invoice->number],
                    ['Issued', $invoice->issue_date?->format('F j, Y')],
                    $invoice->po_number ? ['PO', $invoice->po_number] : null,
                    ['Invoice total', Money::format($invoice->total_cents)],
                ], fn ($r) => $r && $r[1])),
                'buttons' => $buttons,
                'notes' => ['This link was created for '.$to.' and is available until '.$token->expires_at->format('F j, Y').'. Questions about this invoice? Contact '.$business->name().self::contactSuffix($business).'.'],
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
        Audit::log($business, 'invoice.'.($kind === 'reminder' ? 'reminder_queued' : 'send_queued'), $invoice, null, ['recipients' => $recipients], $auditReason);

        return $count;
    }

    /** "Thank you for your payment": confirms receipt and links a copy of each paid invoice stamped PAID. */
    public function queueReceipt(Payment $payment): void
    {
        $payment->loadMissing('client.contacts', 'allocations.invoice');
        if ($payment->receipt_emailed_at) return;
        $business = Business::find($payment->business_id);
        $allocs = $payment->allocations->where('status', 'active')->filter(fn ($a) => $a->invoice?->isIssued());
        $invoices = $allocs->map(fn ($a) => $a->invoice->fresh())->unique('id')->values();
        $to = $invoices->flatMap(fn ($i) => $i->deliveryRecipients())->unique()->values()->all()
            ?: array_filter([$payment->client->billingEmail()]);
        if (! $to) return;

        $details = [
            ['Method', \App\Services\Payments\PaymentService::METHODS[$payment->method] ?? $payment->method],
            ['Date received', $payment->received_date->format('F j, Y')],
        ];
        foreach ($allocs as $a) {
            $details[] = ['Applied to '.$a->invoice->number, Money::format($a->amount_cents)];
            $details[] = [$a->invoice->number.' balance', Money::format($invoices->firstWhere('id', $a->invoice_id)->balance_cents)];
        }
        if (($unapplied = $payment->unappliedCents()) > 0) $details[] = ['Credit on account', Money::format($unapplied)];
        $allPaid = $invoices->isNotEmpty() && $invoices->every(fn ($i) => $i->balance_cents <= 0);

        foreach ($to as $email) {
            $buttons = [];
            foreach ($invoices as $inv) {
                [, $raw] = $this->links->issue($business, 'invoice_view', [
                    'invoice_id' => $inv->id,
                    'contract_version_id' => $inv->contract_version_id,
                    'allowed_actions' => ['view', 'pdf', 'view_contract'],
                    'recipient_email' => $email,
                ], $business->invoice_link_ttl_days);
                $paid = $inv->balance_cents <= 0;
                $buttons[] = ['label' => ($paid ? 'Download paid invoice' : 'Download updated invoice').($invoices->count() > 1 ? ' '.$inv->number : '').' (PDF)',
                    'url' => url('/i/'.$raw).'?d=paid', 'track' => 'paid_pdf'];
            }
            $this->queue($business, 'receipt', $email, 'Thank you for your payment — '.$business->name(), [
                'tag' => 'Payment received',
                'greeting' => 'Hello '.$payment->client->display_name.',',
                'paragraphs' => array_values(array_filter([
                    'Thank you for your payment. We’ve received it and marked it as received on our records.',
                    $invoices->isEmpty() ? null : ($allPaid
                        ? 'A copy of your invoice marked PAID is available below for your records.'
                        : 'An updated copy of your invoice showing the remaining balance is available below.'),
                ])),
                'highlight' => ['label' => 'Payment received', 'value' => Money::format($payment->amount_cents), 'sub' => $allPaid ? 'Paid in full — thank you!' : null],
                'details' => $details,
                'buttons' => $buttons,
                'signoff' => 'With thanks,',
            ], ['payment_id' => $payment->id, 'invoice_id' => $invoices->count() === 1 ? $invoices->first()->id : null]);
        }
        $payment->receipt_emailed_at = now();
        $payment->save();
        Audit::log($business, 'payment.receipt_queued', $payment, null, ['recipients' => $to, 'invoices' => $invoices->pluck('number')->all()]);
    }

    public function queueContractInvitation(ContractRecipient $r, string $url, bool $reminder = false): void
    {
        $v = $r->version()->with('contract')->first();
        $business = Business::find($r->business_id);
        $this->queue($business, $reminder ? 'contract_reminder' : 'contract_invitation', $r->email,
            $reminder ? 'Reminder from '.$business->name().': “'.$v->title.'” is ready to sign' : $business->name().' sent you “'.$v->title.'” to sign', [
                'tag' => 'Agreement',
                'heading' => $v->title,
                'greeting' => 'Hello '.$r->name.',',
                'paragraphs' => [$business->name().' has shared this agreement with you to review and sign online. You can read the full document before deciding.'],
                'buttons' => [['label' => 'Review agreement', 'url' => $url, 'track' => 'sign']],
                'notes' => array_filter([
                    'Prefer to sign on paper or have questions? Contact '.$business->name().self::contactSuffix($business).'.',
                    $v->expires_at ? 'This link was created for '.$r->email.' and is available until '.$v->expires_at->format('F j, Y').'.' : null,
                ]),
            ], ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    /** @param \Illuminate\Support\Collection<int, ContractRecipient> $parties */
    public function queueExecutedCopy(ContractRecipient $r, ContractVersion $v, string $url, $parties): void
    {
        $business = Business::find($r->business_id);
        $contract = $v->contract;
        $tz = $business->timezone ?: 'America/New_York';
        $signers = $parties->where('role', '!=', 'cc')->map(fn ($p) => [
            ($p->typed_name ?: $p->name).($p->role === 'countersigner' ? ' ('.$business->name().')' : ''),
            $p->signed_at ? 'Signed '.$p->signed_at->timezone($tz)->format('M j, Y g:ia T') : 'Signed',
        ])->values()->all();
        $this->queue($business, 'contract_completed', $r->email, 'Signed copy: '.$v->title, [
            'tag' => 'Agreement',
            'heading' => $v->title,
            'greeting' => 'Hello '.$r->name.',',
            'paragraphs' => [
                'All parties have signed “'.$v->title.'”. The agreement is now fully executed.',
                'View it online with every signature and the full signing evidence, or download the signed PDF and the signature certificate for your records.',
            ],
            'highlight' => ['label' => 'Fully executed', 'value' => $v->completed_at?->timezone($tz)->format('F j, Y') ?? now($tz)->format('F j, Y'),
                'sub' => trim(($contract?->number ?? '').' · version '.$v->version, ' ·')],
            'details' => array_merge($signers, [['Signed document SHA-256', substr((string) $v->signed_sha256, 0, 16).'…']]),
            'buttons' => array_values(array_filter([
                ['label' => 'View executed agreement', 'url' => $url, 'track' => 'agreement'],
                $v->signed_document_id ? ['label' => 'Download signed PDF', 'url' => $url.'?d=signed', 'track' => 'signed_pdf'] : null,
                $v->certificate_document_id ? ['label' => 'Signature certificate', 'url' => $url.'?d=certificate', 'track' => 'certificate'] : null,
            ])),
            'notes' => ['These links were created for '.$r->email.' and are available for 90 days. Please download a copy for your records.'],
            'signoff' => 'Thank you,',
        ], ['contract_version_id' => $v->id, 'contract_recipient_id' => $r->id]);
    }

    public function queueW9(Business $business, \App\Models\Client $client, string $to, string $url, \App\Models\PublicToken $token, ?string $message = null): EmailOutbox
    {
        return $this->queue($business, 'w9', $to, 'Form W-9 from '.$business->name(), [
            'tag' => 'Form W-9',
            'greeting' => 'Hello'.($client->display_name ? ' '.$client->display_name : '').',',
            'paragraphs' => array_values(array_filter([
                $message,
                $business->name().' has provided its completed IRS Form W-9 for your records. You can download it as a PDF below.',
            ])),
            'buttons' => [['label' => 'Download W-9', 'url' => $url]],
            'notes' => ['This link was created for '.$to.' and is available until '.$token->expires_at->format('F j, Y')
                .($token->max_uses ? ' (up to '.$token->max_uses.' downloads)' : '').'. Need it again later? Contact '.$business->name().self::contactSuffix($business).'.'],
            'signoff' => 'Thank you,',
        ]);
    }

    public function queueSigningCode(ContractRecipient $r, string $code): void
    {
        $business = Business::find($r->business_id);
        $title = $r->version()->value('title');
        $this->queue($business, 'signing_code', $r->email, 'Your code for signing “'.$title.'”', [
            'tag' => 'Signing code',
            'paragraphs' => ['You asked for a code to sign “'.$title.'” with '.$business->name().'. Enter it on the signing page to continue.'],
            'code' => $code,
            'notes' => ['The code works for '.config('finance.signing.otp_ttl_minutes').' minutes. If you didn’t start signing this agreement, you can ignore this email — nothing else is needed.'],
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
            'notes' => ['You’ll sign in with your McNutt Cloud account. This invitation is available until '.$inv->expires_at->format('F j, Y').'.'],
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
