<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Money
{
    public static function format(?int $cents, bool $withSymbol = true): string
    {
        $cents = (int) $cents;
        $neg = $cents < 0;
        $abs = abs($cents);
        $str = number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($neg ? '-' : '').($withSymbol ? '$' : '').$str;
    }

    /** Parse user-entered dollars ("1,234.56", "$10") into integer cents with half-up rounding. */
    public static function parse(string|int|float|null $input): int
    {
        if ($input === null || $input === '') {
            return 0;
        }
        $clean = str_replace([',', '$', ' '], '', (string) $input);
        if (! preg_match('/^-?\d*(\.\d+)?$/', $clean) || $clean === '-' || $clean === '') {
            throw new InvalidArgumentException('Invalid amount');
        }

        return BigDecimal::of($clean)->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    public static function toDecimalString(int $cents): string
    {
        return (string) BigDecimal::ofUnscaledValue($cents, 2);
    }

    /** qty * price (decimal strings) -> cents, half-up. */
    public static function extend(string $qty, string $unitPrice): int
    {
        return BigDecimal::of($qty)->multipliedBy(BigDecimal::of($unitPrice))
            ->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /** cents * percent / 100, half-up. */
    public static function percentOf(int $cents, string $percent): int
    {
        return BigDecimal::of($cents)->multipliedBy(BigDecimal::of($percent))
            ->dividedBy(100, 6, RoundingMode::HALF_UP)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * Allocate $total cents across $weights proportionally (largest remainder, ties by order).
     * Sum of result always equals $total. Weights must be non-negative.
     *
     * @param  array<int|string,int>  $weights
     * @return array<int|string,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $result = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0 || $total === 0) {
            return $result;
        }
        $sign = $total < 0 ? -1 : 1;
        $abs = abs($total);
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $k => $w) {
            $exact = BigDecimal::of($abs)->multipliedBy($w)->dividedBy($sum, 10, RoundingMode::DOWN);
            $floor = $exact->toScale(0, RoundingMode::DOWN)->toInt();
            $result[$k] = $floor;
            $allocated += $floor;
            $remainders[] = [$k, $exact->minus($floor)];
        }
        $left = $abs - $allocated;
        usort($remainders, function ($a, $b) {
            return $b[1]->compareTo($a[1]);
        });
        for ($i = 0; $i < $left; $i++) {
            $result[$remainders[$i % count($remainders)][0]]++;
        }
        if ($sign < 0) {
            foreach ($result as $k => $v) {
                $result[$k] = -$v;
            }
        }

        return $result;
    }
}
