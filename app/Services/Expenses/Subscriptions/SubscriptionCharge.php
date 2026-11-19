<?php

namespace App\Services\Expenses\Subscriptions;

use Illuminate\Support\Carbon;

/** One bill/charge from a subscription provider, normalized for the expense import review. */
final class SubscriptionCharge
{
    /** @param list<array{description:string, amount_cents:int}> $lines itemized build-up; must sum to $amountCents */
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly Carbon $date,
        public readonly string $vendor,
        public readonly string $category,
        public readonly string $paidWith,
        public readonly string $statement,
        public readonly string $description,
        public readonly int $amountCents,
        public readonly ?string $invoiceNumber = null,
        public readonly ?Carbon $periodStart = null,
        public readonly ?Carbon $periodEnd = null,
        public readonly array $lines = [],
    ) {}

    public function toArray(string $sourceName): array
    {
        return [
            'source' => $this->source,
            'source_name' => $sourceName,
            'external_id' => $this->externalId,
            'date' => $this->date->toDateString(),
            'vendor' => $this->vendor,
            'category' => $this->category,
            'paid_with' => $this->paidWith,
            'statement' => $this->statement,
            'description' => $this->description,
            'amount_cents' => $this->amountCents,
            'invoice_number' => $this->invoiceNumber,
            'period_start' => $this->periodStart?->toDateString(),
            'period_end' => $this->periodEnd?->toDateString(),
            'lines' => $this->lines,
        ];
    }
}
