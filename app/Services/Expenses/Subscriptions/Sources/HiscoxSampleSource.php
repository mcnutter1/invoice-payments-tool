<?php

namespace App\Services\Expenses\Subscriptions\Sources;

use App\Models\Business;
use App\Services\Expenses\Subscriptions\SampleSubscriptionSource;
use App\Services\Expenses\Subscriptions\SubscriptionCharge;
use Illuminate\Support\Carbon;

/** Hiscox small-business insurance for a consultancy: professional liability (E&O) + general liability, paid in monthly installments. */
class HiscoxSampleSource extends SampleSubscriptionSource
{
    public const PROFESSIONAL_LIABILITY_CENTS = 5792;
    public const GENERAL_LIABILITY_CENTS = 3075;

    public function key(): string { return 'hiscox'; }
    public function name(): string { return 'Hiscox Business Insurance'; }
    public function summary(): string { return 'Professional liability (E&O) + general liability, monthly installments'; }
    protected function epochOffset(): int { return 20; }

    protected function build(Business $b, int $cycle, Carbon $date, Carbon $periodStart, Carbon $periodEnd): SubscriptionCharge
    {
        $policy = 'P100.'.$this->digits($b, 'policy-a', 3).'.'.$this->digits($b, 'policy-b', 3).'.'.$this->rand($b, 'policy-c', 1, 4);
        // Monthly installments run from the cycle date, i.e. coverage is paid in advance.
        $coverEnd = $date->copy()->addDays(self::CYCLE_DAYS - 1);

        return new SubscriptionCharge(
            source: $this->key(),
            externalId: 'sub:hiscox:'.$policy.':'.$date->toDateString(),
            date: $date,
            vendor: 'Hiscox',
            category: 'Business Insurance',
            paidWith: 'Business Checking (...'.$this->last4($b, 'checking').')',
            statement: 'HISCOX INC INSURANCE NEW YORK NY',
            description: 'Hiscox policy '.$policy.' · monthly installment · coverage '.self::period($date, $coverEnd),
            amountCents: self::PROFESSIONAL_LIABILITY_CENTS + self::GENERAL_LIABILITY_CENTS,
            invoiceNumber: $policy,
            periodStart: $date,
            periodEnd: $coverEnd,
            lines: [
                ['description' => 'Professional Liability (Errors & Omissions) — $1,000,000 per claim / $1,000,000 aggregate', 'amount_cents' => self::PROFESSIONAL_LIABILITY_CENTS],
                ['description' => 'General Liability — $1,000,000 per occurrence / $2,000,000 aggregate', 'amount_cents' => self::GENERAL_LIABILITY_CENTS],
            ],
        );
    }
}
