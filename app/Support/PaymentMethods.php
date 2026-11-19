<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Client;

/** Client-facing payment methods an invoice can offer, and which of them are configured/allowed. */
final class PaymentMethods
{
    /** Display order on invoices and settings. */
    public const INVOICE = ['card', 'ach', 'zelle', 'venmo', 'check'];

    public const LABELS = [
        'card' => 'Credit / debit card',
        'ach' => 'ACH / wire transfer',
        'zelle' => 'Zelle',
        'venmo' => 'Venmo',
        'check' => 'Mailed check',
    ];

    /** Paid outside the app: the client reports it, staff confirm it. */
    public const MANUAL = ['ach', 'zelle', 'venmo', 'check'];

    public static function label(?string $m): string
    {
        return self::LABELS[$m] ?? \App\Services\Payments\PaymentService::METHODS[$m] ?? ucfirst((string) $m);
    }

    /** Methods whose required details are on file. */
    public static function configured(Business $b): array
    {
        return array_values(array_filter(self::INVOICE, fn ($m) => match ($m) {
            'zelle' => (bool) ($b->zelle_email || $b->zelle_phone),
            'check' => (bool) ($b->check_address || $b->addressLines()),
            'ach' => (bool) ($b->ach_routing_number && $b->ach_account_number_encrypted),
            'venmo' => (bool) $b->venmo_handle,
            'card' => (bool) $b->stripe_secret_key_encrypted,
        }));
    }

    /** Business-enabled methods, narrowed by the client's own list when it has one. */
    public static function forClient(Business $b, ?Client $c): array
    {
        $allowed = $b->allowedMethods();
        if ($c && is_array($c->payment_methods)) {
            $allowed = array_values(array_intersect($allowed, $c->payment_methods));
        }

        return self::ordered($allowed);
    }

    /** Requested methods (or the client defaults) limited to what the client may use. */
    public static function forInvoice(Business $b, ?Client $c, ?array $requested): array
    {
        $allowed = self::forClient($b, $c);

        return self::ordered($requested === null ? $allowed : array_intersect($requested, $allowed));
    }

    public static function ordered(array $methods): array
    {
        return array_values(array_intersect(self::INVOICE, $methods));
    }
}
