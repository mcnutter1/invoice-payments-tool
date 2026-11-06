<?php

namespace App\Services\Tax;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\PrivateDocument;
use App\Models\PublicToken;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Notifications\EmailComposer;
use App\Services\PublicAccess\PublicLinkService;
use Illuminate\Support\Facades\Crypt;

/** The business's own IRS Form W-9: settings, PDF generation and expiring delivery links. */
class W9Service
{
    public const CLASSIFICATIONS = [
        'individual' => 'Individual/sole proprietor', 'c_corp' => 'C corporation', 's_corp' => 'S corporation',
        'partnership' => 'Partnership', 'trust' => 'Trust/estate', 'llc' => 'LLC', 'other' => 'Other',
    ];

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly PublicLinkService $links,
        private readonly EmailComposer $email,
        private readonly DocumentStore $docs,
    ) {}

    public function settings(Business $b): array
    {
        $fromEntity = ['llc' => 'llc', 'sole_proprietor' => 'individual', 's_corp' => 's_corp', 'c_corp' => 'c_corp', 'partnership' => 'partnership'];

        return array_merge([
            'name' => $b->legal_name,
            'business_name' => $b->display_name && $b->display_name !== $b->legal_name ? $b->display_name : '',
            'classification' => $fromEntity[$b->entity_type] ?? '',
            'llc_code' => '', 'other_text' => '', 'foreign_partners' => false,
            'exempt_payee_code' => '', 'fatca_code' => '',
            'address' => trim(implode(', ', array_filter([$b->address_line1, $b->address_line2]))),
            'city_state_zip' => trim(implode(' ', array_filter([$b->city ? $b->city.',' : null, $b->state, $b->postal_code]))),
            'account_numbers' => '', 'tin_type' => 'ein', 'backup_withholding' => false,
            'signer_name' => '', 'signer_membership_id' => null,
            'link_ttl_days' => 14, 'max_downloads' => 5, 'allow_client_requests' => true,
        ], (array) ($b->w9_settings ?? []));
    }

    /** Digits only; falls back to the EIN saved on the business profile. */
    public function tin(Business $b): ?string
    {
        foreach ([$b->w9_tin_encrypted, $b->tax_id_encrypted] as $enc) {
            if (! $enc) continue;
            $digits = preg_replace('/\D/', '', (string) decrypt($enc));
            if (strlen($digits) === 9) return $digits;
        }

        return null;
    }

    public function saveTin(Business $b, string $tin): void
    {
        $b->forceFill(['w9_tin_encrypted' => Crypt::encrypt(preg_replace('/\D/', '', $tin))])->save();
    }

    /** @return list<string> what still has to be filled in before a W-9 can be issued */
    public function missing(Business $b): array
    {
        $s = $this->settings($b);

        return array_values(array_filter([
            trim((string) $s['name']) === '' ? 'Name (line 1)' : null,
            ! isset(self::CLASSIFICATIONS[$s['classification']]) ? 'Federal tax classification (line 3a)' : null,
            $s['classification'] === 'llc' && ! in_array($s['llc_code'], ['C', 'S', 'P'], true) ? 'LLC tax classification code (C, S or P)' : null,
            $s['classification'] === 'other' && trim((string) $s['other_text']) === '' ? 'Description for “Other” classification' : null,
            trim((string) $s['address']) === '' || trim((string) $s['city_state_zip']) === '' ? 'Address (lines 5 and 6)' : null,
            ! $this->tin($b) ? 'Taxpayer identification number (9 digits)' : null,
            trim((string) $s['signer_name']) === '' ? 'Signer name' : null,
        ]));
    }

    public function ready(Business $b): bool
    {
        return ! $this->missing($b);
    }

    public function render(Business $b, ?Client $requester = null): string
    {
        if ($missing = $this->missing($b)) throw new DomainException('Complete the W-9 settings first: '.implode(', ', $missing).'.');
        $s = $this->settings($b);
        $signature = null;
        if ($s['signer_membership_id']) {
            $m = Membership::where('business_id', $b->id)->where('status', 'active')->find($s['signer_membership_id']);
            if ($m?->signature_document_id) {
                $signature = $this->docs->dataUri(PrivateDocument::where('business_id', $b->id)->find($m->signature_document_id));
            }
        }

        return $this->pdf->render('pdf.w9', [
            's' => $s, 'tin' => $this->tin($b), 'signature' => $signature,
            'date' => $b->localToday()->format('m/d/Y'),
            'requester' => $requester ? array_values(array_filter(array_merge([$requester->legal_name ?: $requester->display_name], $requester->billingAddressLines()))) : [],
        ], true);
    }

    /**
     * Email expiring W-9 download links. $trigger is 'staff' (sent from the client page) or 'client' (requested from an invoice).
     *
     * @param list<string> $emails
     */
    public function send(Business $b, Client $client, array $emails, string $trigger, ?Invoice $invoice = null, ?string $message = null): int
    {
        if ($missing = $this->missing($b)) throw new DomainException('Complete the W-9 settings first: '.implode(', ', $missing).'.');
        $s = $this->settings($b);
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails))));
        if (! $emails) throw new DomainException('Add at least one recipient email.');
        foreach ($emails as $to) {
            [$token, $raw] = $this->links->issue($b, 'w9', [
                'client_id' => $client->id, 'invoice_id' => $invoice?->id, 'recipient_email' => $to,
                'allowed_actions' => ['w9'], 'max_uses' => (int) $s['max_downloads'],
            ], (int) $s['link_ttl_days']);
            $this->email->queueW9($b, $client, $to, url('/w9/'.$raw), $token, $message);
        }
        Audit::log($b, 'w9.sent', $client, null, ['recipients' => $emails, 'trigger' => $trigger, 'invoice' => $invoice?->number],
            $trigger === 'client' ? 'Requested by the client from invoice '.$invoice?->number : null, $trigger === 'client' ? 'client' : null);

        return count($emails);
    }

    public function remaining(PublicToken $t): ?int
    {
        return $t->max_uses ? max(0, $t->max_uses - $t->use_count) : null;
    }
}
