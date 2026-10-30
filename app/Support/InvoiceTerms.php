<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Client;

/** Invoice terms: the business's standard terms (with placeholders filled in), followed by any client-specific terms. */
final class InvoiceTerms
{
    /** Placeholders: {payment_terms}, {business_name}, {client_name}. */
    public const STANDARD = "Payment terms: {payment_terms}. Payment is due in full by the due date shown on this invoice, in US dollars, without deduction, withholding or set-off.\n\n"
        ."Late payment: Past-due balances accrue a late charge of 1.5% per month (18% per year), or the maximum rate permitted by law if lower, from the due date until paid in full. {business_name} may suspend work and withhold deliverables while any balance is past due. {client_name} is responsible for all costs of collection, including reasonable attorneys’ fees.\n\n"
        ."No refunds or cancellations: All fees are earned when invoiced and are non-refundable. Services ordered or performed may not be cancelled, and invoiced amounts remain payable in full regardless of use, schedule changes or early termination.\n\n"
        ."Disputes: Any dispute must be submitted in writing within 10 days of the invoice date, or this invoice is deemed accepted. Undisputed amounts remain due by the due date.\n\n"
        ."Ownership: Title to deliverables, and any license to use them, passes to {client_name} only upon payment in full.\n\n"
        .'Agreement: Where this invoice relates to a signed agreement with {business_name}, these terms supplement that agreement.';

    public static function label(int $days): string
    {
        return $days <= 0 ? 'Due on receipt' : 'Net '.$days.' (due within '.$days.' days of the invoice date)';
    }

    /** The client's payment terms from their profile, falling back to the business default. */
    public static function paymentTerms(Business $business, ?Client $client): string
    {
        return self::label((int) ($client?->default_terms_days ?? $business->default_terms_days));
    }

    /** Default terms template; always leads with the payment terms so they track the client profile. */
    public static function standard(Business $business): string
    {
        $text = trim((string) $business->default_terms_text) ?: self::STANDARD;
        if (! str_contains($text, '{payment_terms}')) $text = "Payment terms: {payment_terms}.\n\n".$text;

        return $text;
    }

    public static function forClient(Business $business, ?Client $client): string
    {
        $specific = trim((string) $client?->invoice_terms);

        return self::standard($business).($specific !== '' ? "\n\nClient-specific terms: ".$specific : '');
    }

    /** Fill placeholders for display. Payment terms always come from the client profile (else the business default). */
    public static function resolve(?string $terms, \App\Models\Invoice $invoice): string
    {
        $terms = (string) $terms;
        if (! str_contains($terms, '{')) return $terms;
        $business = $invoice->business ?? Business::find($invoice->business_id);
        $client = $invoice->client ?? Client::find($invoice->client_id);

        return strtr($terms, [
            '{payment_terms}' => self::paymentTerms($business, $client),
            '{business_name}' => $business->legal_name ?: $business->name(),
            '{client_name}' => $client ? ($client->legal_name ?: $client->display_name) : 'The client',
        ]);
    }

    /** Escaped HTML: paragraphs, with a leading "Heading:" in bold. */
    public static function toHtml(?string $terms, string $headingStyle = ''): string
    {
        $paras = preg_split('/\n\s*\n/', trim((string) $terms)) ?: [];

        return implode('', array_map(function ($p) use ($headingStyle) {
            $p = e(trim($p));
            $p = preg_replace('/^([A-Z][A-Za-z ,&’\'\-]{1,40}):/u', '<strong'.($headingStyle ? ' style="'.$headingStyle.'"' : '').'>$1:</strong>', $p, 1);

            return '<p>'.nl2br($p, false).'</p>';
        }, array_filter($paras, fn ($p) => trim($p) !== '')));
    }
}
