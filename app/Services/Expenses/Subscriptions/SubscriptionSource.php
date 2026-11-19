<?php

namespace App\Services\Expenses\Subscriptions;

use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * A provider whose recurring bills can be pulled into expenses (AWS, Microsoft, an insurer…).
 * Implement this for a live API connector and register the class in config('finance.subscription_sources').
 */
interface SubscriptionSource
{
    /** Stable key, used in external IDs (e.g. "aws"). Never change it once charges are imported. */
    public function key(): string;

    public function name(): string;

    /** One line describing what is billed, shown on the import page. */
    public function summary(): string;

    /** "sample" (built-in demo data) or "api" (live connector). */
    public function mode(): string;

    public function isConfigured(Business $b): bool;

    /** Expected date of the next charge after $after, if the source knows its billing cycle. */
    public function nextChargeDate(Business $b, Carbon $after): ?Carbon;

    /** @return list<SubscriptionCharge> charges dated within [$from, $to], oldest first */
    public function charges(Business $b, Carbon $from, Carbon $to): array;
}
