<?php

namespace App\Services\Expenses\Subscriptions\Sources;

use App\Models\Business;
use App\Services\Expenses\Subscriptions\SampleSubscriptionSource;
use App\Services\Expenses\Subscriptions\SubscriptionCharge;
use Illuminate\Support\Carbon;

/**
 * Microsoft 365 for 2 users: Business Premium (with Teams) + Microsoft 365 Copilot, annual commitment billed monthly.
 * US list prices per user/month; Microsoft adds 5% when an annual commitment is paid monthly.
 */
class Microsoft365SampleSource extends SampleSubscriptionSource
{
    public const SEATS = 2;
    public const BUSINESS_PREMIUM_CENTS = 2200;
    public const COPILOT_CENTS = 3000;
    public const MONTHLY_BILLING_UPLIFT = 0.05;

    public function key(): string { return 'm365'; }
    public function name(): string { return 'Microsoft 365'; }
    public function summary(): string { return self::SEATS.' users · Business Premium (with Teams) + Microsoft 365 Copilot'; }
    protected function epochOffset(): int { return 11; }

    /** @return list<array{description:string, amount_cents:int}> */
    public static function buildUp(): array
    {
        $premium = self::SEATS * self::BUSINESS_PREMIUM_CENTS;
        $copilot = self::SEATS * self::COPILOT_CENTS;
        $m = fn (int $c) => '$'.number_format($c / 100, 2);

        return [
            ['description' => 'Microsoft 365 Business Premium (with Teams) — '.self::SEATS.' licenses × '.$m(self::BUSINESS_PREMIUM_CENTS).'/user/month, annual commitment', 'amount_cents' => $premium],
            ['description' => 'Microsoft 365 Copilot — '.self::SEATS.' licenses × '.$m(self::COPILOT_CENTS).'/user/month, annual commitment', 'amount_cents' => $copilot],
            ['description' => 'Monthly billing frequency (+'.(self::MONTHLY_BILLING_UPLIFT * 100).'% on annual-commitment licenses)', 'amount_cents' => (int) round(($premium + $copilot) * self::MONTHLY_BILLING_UPLIFT)],
        ];
    }

    protected function build(Business $b, int $cycle, Carbon $date, Carbon $periodStart, Carbon $periodEnd): SubscriptionCharge
    {
        $lines = self::buildUp();
        $invoice = 'E0'.$this->digits($b, 'tenant', 3).$this->letters($b, 'inv'.$cycle, 5);

        return new SubscriptionCharge(
            source: $this->key(),
            externalId: 'sub:m365:'.$invoice,
            date: $date,
            vendor: 'Microsoft',
            category: 'Software & Subscriptions',
            paidWith: 'Business Platinum Card® (...'.$this->last4($b, 'card').')',
            statement: 'MSFT * '.$invoice.' MSBILL.INFO WA',
            description: 'Microsoft 365 invoice '.$invoice.' · '.self::SEATS.' users · '.self::period($periodStart, $periodEnd),
            amountCents: array_sum(array_column($lines, 'amount_cents')),
            invoiceNumber: $invoice,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            lines: $lines,
        );
    }
}
