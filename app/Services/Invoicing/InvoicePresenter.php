<?php

namespace App\Services\Invoicing;

use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PrivateDocument;
use App\Services\Documents\DocumentStore;
use App\Support\Money;

/** Single document view-model shared by HTML preview, public page and PDF. */
class InvoicePresenter
{
    public function __construct(private readonly DocumentStore $docs) {}

    public static function businessSnapshot(Business $b): array
    {
        return [
            'name' => $b->name(),
            'legal_name' => $b->legal_name,
            'address' => $b->addressLines(),
            'phone' => $b->phone,
            'email' => $b->public_email,
            'website' => $b->website,
            'tax_id' => $b->show_tax_id && $b->tax_id_encrypted ? decrypt($b->tax_id_encrypted) : null,
            'logo_document_id' => $b->logo_document_id,
        ];
    }

    public static function instructionsSnapshot(Business $b, array $methods): array
    {
        $allowed = array_values(array_intersect($methods ?: $b->allowedMethods(), $b->allowedMethods()));

        return [
            'methods' => $allowed,
            'zelle' => in_array('zelle', $allowed, true) ? [
                'recipient_name' => $b->zelle_recipient_name,
                'email' => $b->zelle_email,
                'phone' => $b->zelle_phone,
                'qr_document_id' => $b->zelle_qr_document_id,
            ] : null,
            'check' => in_array('check', $allowed, true) ? [
                'payee' => $b->check_payee ?: $b->legal_name,
                'address' => $b->check_address ?: implode("\n", $b->addressLines()),
            ] : null,
        ];
    }

    public static function templateSnapshot(Business $b): array
    {
        return [
            'template' => $b->brand('template'),
            'accent' => $b->brand('accent'),
            'font' => $b->brand('font'),
            'header_text' => $b->brand('header_text'),
            'footer_text' => $b->brand('footer_text'),
            'show_sku' => (bool) $b->brand('show_sku'),
            'show_quantity' => (bool) $b->brand('show_quantity'),
            'due_date_prominent' => (bool) $b->brand('due_date_prominent'),
        ];
    }

    public function viewModel(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'taxLines', 'business', 'client.contacts', 'contractVersion.contract']);
        $live = $invoice->isDraft();
        $business = $live ? self::businessSnapshot($invoice->business) : ($invoice->business_snapshot ?? []);
        $client = $live ? $invoice->client->snapshot() : ($invoice->client_snapshot ?? []);
        $instructions = $live ? self::instructionsSnapshot($invoice->business, $invoice->payment_methods ?? []) : ($invoice->instructions_snapshot ?? []);
        $z = $instructions['zelle'] ?? null;
        $b = $invoice->business;
        // A QR added later is shown only if it belongs to the exact Zelle recipient frozen on the invoice.
        if ($z && empty($z['qr_document_id']) && $b->zelle_qr_document_id
            && ($z['recipient_name'] ?? null) === $b->zelle_recipient_name && ($z['email'] ?? null) === $b->zelle_email && ($z['phone'] ?? null) === $b->zelle_phone) {
            $instructions['zelle']['qr_document_id'] = $b->zelle_qr_document_id;
        }
        $zelleQr = ! empty($instructions['zelle']['qr_document_id'])
            ? $this->docs->dataUri(PrivateDocument::where('business_id', $invoice->business_id)->find($instructions['zelle']['qr_document_id']))
            : null;
        $template = $live ? self::templateSnapshot($invoice->business) : ($invoice->template_snapshot ?? self::templateSnapshot($invoice->business));
        $logo = ! empty($business['logo_document_id'])
            ? $this->docs->dataUri(PrivateDocument::where('business_id', $invoice->business_id)->find($business['logo_document_id']))
            : null;
        $memo = $invoice->number ? 'Invoice '.$invoice->number : null;

        return [
            'invoice' => $invoice,
            'business' => $business,
            'client' => $client,
            'instructions' => $instructions,
            'zelleQr' => $zelleQr,
            'template' => $template,
            'logo' => $logo,
            'memo' => $memo,
            'lines' => $invoice->lines,
            'tax_lines' => $invoice->taxLines,
            'money' => fn (int $c) => Money::format($c),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($template['accent'] ?? '')) ? $template['accent'] : '#1f5f8b',
            'font' => in_array($template['font'] ?? '', \App\Services\Documents\PdfRenderer::FONTS, true) ? $template['font'] : 'DejaVu Sans',
        ];
    }
}
