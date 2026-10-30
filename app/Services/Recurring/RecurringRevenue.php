<?php

namespace App\Services\Recurring;

use App\Models\Business;
use App\Models\Product;
use App\Models\RecurringSchedule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Annualized value of recurring schedules (pre-tax, before credits). */
class RecurringRevenue
{
    public const PER_YEAR = ['week' => 52, 'month' => 12, 'quarter' => 4, 'year' => 1];

    /** @param array<int,string> $catalogPrices product id => current default price */
    public static function periodCents(RecurringSchedule $s, array $catalogPrices = []): int
    {
        $total = 0;
        foreach ($s->lines as $l) {
            $price = ($s->price_policy === 'catalog' && $l->product_id && isset($catalogPrices[$l->product_id])) ? $catalogPrices[$l->product_id] : (string) $l->unit_price;
            $total += BigDecimal::of((string) $l->quantity)->multipliedBy($price)->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
        }

        return $total;
    }

    public static function annualCents(RecurringSchedule $s, array $catalogPrices = []): int
    {
        $per = self::PER_YEAR[$s->cadence] ?? 12;

        return (int) round(self::periodCents($s, $catalogPrices) * $per / max(1, (int) $s->interval));
    }

    /** Active and not past its end date. */
    public static function counts(RecurringSchedule $s, \DateTimeInterface $today): bool
    {
        return $s->status === 'active' && (! $s->end_date || $s->end_date->gte($today));
    }

    /**
     * @return array{arr:int, mrr:int, count:int, by_schedule:array<int,int>, by_client:array<int,int>}
     */
    public function summary(Business $business, ?int $clientId = null, ?Collection $schedules = null): array
    {
        $schedules ??= RecurringSchedule::with('lines')->where('business_id', $business->id)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))->get();
        $prices = Product::where('business_id', $business->id)->pluck('default_price', 'id')->map(fn ($p) => (string) $p)->all();
        $today = $business->localToday();
        $by = [];
        $byClient = [];
        $arr = 0;
        $count = 0;
        foreach ($schedules as $s) {
            $s->loadMissing('lines');
            $annual = self::annualCents($s, $prices);
            $by[$s->id] = $annual;
            if (! self::counts($s, $today)) continue;
            $arr += $annual;
            $count++;
            $byClient[$s->client_id] = ($byClient[$s->client_id] ?? 0) + $annual;
        }

        return ['arr' => $arr, 'mrr' => (int) round($arr / 12), 'count' => $count, 'by_schedule' => $by, 'by_client' => $byClient];
    }
}
