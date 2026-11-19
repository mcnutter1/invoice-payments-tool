<?php

namespace App\Services\Expenses\Subscriptions;

use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * Built-in demo connector: bills every 30 days from a per-business anchor date. Amounts, invoice numbers and
 * anchors are derived from the business ULID, so repeated pulls return the same charges and de-duplicate.
 */
abstract class SampleSubscriptionSource implements SubscriptionSource
{
    protected const CYCLE_DAYS = 30;

    /** Days after the sample epoch where this source's first cycle starts (spreads sources apart). */
    abstract protected function epochOffset(): int;

    abstract protected function build(Business $b, int $cycle, Carbon $date, Carbon $periodStart, Carbon $periodEnd): SubscriptionCharge;

    public function mode(): string
    {
        return 'sample';
    }

    public function isConfigured(Business $b): bool
    {
        return true;
    }

    public function charges(Business $b, Carbon $from, Carbon $to): array
    {
        $to = $to->copy()->min($b->localToday());
        $out = [];
        for ($cycle = $this->firstCycleOnOrAfter($b, $from); ($date = $this->cycleDate($b, $cycle))->lte($to); $cycle++) {
            $out[] = $this->build($b, $cycle, $date, $date->copy()->subDays(self::CYCLE_DAYS), $date->copy()->subDay());
        }

        return $out;
    }

    public function nextChargeDate(Business $b, Carbon $after): ?Carbon
    {
        return $this->cycleDate($b, $this->firstCycleOnOrAfter($b, $after->copy()->addDay()));
    }

    protected function anchor(Business $b): Carbon
    {
        // Jitter is per business only, so sources keep their relative spacing and never bill on the same day.
        $jitter = hexdec(substr(hash('sha256', $b->ulid.'|anchor'), 0, 8)) % self::CYCLE_DAYS;

        return Carbon::parse('2024-01-01')->addDays($this->epochOffset() + $jitter);
    }

    protected function cycleDate(Business $b, int $cycle): Carbon
    {
        return $this->anchor($b)->addDays($cycle * self::CYCLE_DAYS);
    }

    private function firstCycleOnOrAfter(Business $b, Carbon $date): int
    {
        $days = (int) floor($this->anchor($b)->diffInDays($date->copy()->startOfDay(), false));

        return $days <= 0 ? 0 : intdiv($days + self::CYCLE_DAYS - 1, self::CYCLE_DAYS);
    }

    /** Deterministic 0..1 value for this business + salt. */
    protected function unit(Business $b, string $salt): float
    {
        return hexdec(substr(hash('sha256', $b->ulid.'|'.$this->key().'|'.$salt), 0, 12)) / 0xFFFFFFFFFFFF;
    }

    protected function rand(Business $b, string $salt, int $min, int $max): int
    {
        return $min + (int) floor($this->unit($b, $salt) * ($max - $min + 1 - 1e-9));
    }

    protected function digits(Business $b, string $salt, int $n): string
    {
        $h = preg_replace('/\D/', '', hash('sha256', $b->ulid.'|'.$this->key().'|'.$salt).hash('sha256', $salt.'|'.$b->ulid));

        return (string) ((int) $h[0] ?: 1).substr($h, 1, $n - 1);
    }

    /** Last four digits of the business's sample card or bank account; shared by every source. */
    protected function last4(Business $b, string $account): string
    {
        return (string) (1000 + hexdec(substr(hash('sha256', $b->ulid.'|account|'.$account), 0, 8)) % 9000);
    }

    protected function letters(Business $b, string $salt, int $n): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        $h = hash('sha256', $b->ulid.'|'.$this->key().'|'.$salt);
        $out = '';
        for ($i = 0; $i < $n; $i++) $out .= $alphabet[hexdec(substr($h, $i * 2, 2)) % strlen($alphabet)];

        return $out;
    }

    protected static function period(Carbon $start, Carbon $end): string
    {
        return $start->format($start->year === $end->year ? 'M j' : 'M j, Y').' – '.$end->format('M j, Y');
    }
}
