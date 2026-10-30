<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Client;

/** Invoice terms: the business's standard terms, followed by any client-specific terms. */
final class InvoiceTerms
{
    public const STANDARD = "1. Payment is due by the due date shown on this invoice.\n"
        ."2. Please include the invoice number with your payment so it can be applied correctly.\n"
        ."3. Questions about this invoice should be raised within 10 days of receipt; undisputed amounts remain due on the due date.\n"
        ."4. Work may be paused while a balance is past due.\n"
        .'5. All amounts are in US dollars.';

    public static function standard(Business $business): string
    {
        return trim((string) $business->default_terms_text) ?: self::STANDARD;
    }

    public static function forClient(Business $business, ?Client $client): string
    {
        $specific = trim((string) $client?->invoice_terms);

        return self::standard($business).($specific !== '' ? "\n\nAdditional terms for ".($client->legal_name ?: $client->display_name).":\n".$specific : '');
    }
}
