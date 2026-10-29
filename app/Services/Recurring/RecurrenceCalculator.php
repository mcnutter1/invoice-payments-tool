<?php

namespace App\Services\Recurring;

use App\Models\RecurringSchedule;
use App\Services\Invoicing\InvoiceCalculator;
use Illuminate\Support\Carbon;

/**
 * Deterministic occurrence math on local calendar dates. Every occurrence is computed from the start
 * date (never from the previous occurrence), so month-end clamping restores the anchor day later and
 * leap years are handled by the calendar. Runs are keyed by local date, so DST never shifts a period.
 */
class RecurrenceCalculator
{
    public function occurrence(RecurringSchedule $s, int $n): Carbon
    {
        $start = Carbon::parse($s->start_date->toDateString());
        $interval = max(1, (int) $s->interval);
        if ($s->cadence === 'week') {
            return $start->copy()->addWeeks($n * $interval);
        }
        $months = $n * $interval * match ($s->cadence) {
            'quarter' => 3,
            'year' => 12,
            default => 1,
        };
        $anchor = (int) ($s->anchor_day ?: $start->day);
        $first = $start->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $first->copy()->day(min($anchor, $first->daysInMonth));
    }

    /** First occurrence strictly after $date. */
    public function nextAfter(RecurringSchedule $s, Carbon $date): ?Carbon
    {
        for ($n = 0; $n < 5000; $n++) {
            $o = $this->occurrence($s, $n);
            if ($o->gt($date)) {
                return ($s->end_date && $o->gt($s->end_date)) ? null : $o;
            }
        }

        return null;
    }

    /** @return list<Carbon> */
    public function upcoming(RecurringSchedule $s, int $count = 3): array
    {
        $out = [];
        $d = $s->next_run_date ? Carbon::parse($s->next_run_date->toDateString()) : null;
        while ($d && count($out) < $count) {
            if ($s->end_date && $d->gt($s->end_date)) break;
            $out[] = $d;
            $d = $this->nextAfter($s, $d);
        }

        return $out;
    }

    public function firstOnOrAfter(RecurringSchedule $s, Carbon $date): ?Carbon
    {
        return $this->nextAfter($s, $date->copy()->subDay());
    }

    public static function periodKey(Carbon $d): string
    {
        return $d->toDateString();
    }

    public function scheduleAmountCents(RecurringSchedule $s): int
    {
        $s->loadMissing('lines', 'client');
        $lines = $s->lines->map(function ($l) {
            $rate = $l->tax_rate_id ? (string) \App\Models\TaxRate::find($l->tax_rate_id)?->rate : '0';

            return ['quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'tax_rate' => $rate];
        })->all();
        if (! $lines) return 0;

        return app(InvoiceCalculator::class)->calculate($lines, 'none', 0, (bool) $s->client?->tax_exempt)['total_cents'];
    }
}
