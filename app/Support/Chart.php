<?php

namespace App\Support;

/** Tiny server-side SVG chart helpers (no JavaScript, CSP-safe). */
final class Chart
{
    public static function compact(int $cents): string
    {
        $d = $cents / 100;
        $abs = abs($d);
        $s = match (true) {
            $abs >= 1_000_000 => rtrim(rtrim(number_format($abs / 1_000_000, 1), '0'), '.').'M',
            $abs >= 10_000 => number_format($abs / 1000).'k',
            $abs >= 1_000 => rtrim(rtrim(number_format($abs / 1000, 1), '0'), '.').'k',
            default => number_format($abs),
        };

        return ($d < 0 ? '−$' : '$').$s;
    }

    /** Round up to a "nice" axis maximum (1, 2, 2.5, 5 × 10^n). */
    public static function niceMax(int $value): int
    {
        if ($value <= 0) return 100_00;
        $exp = 10 ** floor(log10($value));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $exp >= $value) return (int) ($m * $exp);
        }

        return (int) (10 * $exp);
    }

    /** @return list<array{0:float,1:float}> */
    public static function points(array $values, float $w, float $h, int $max, float $padX = 0): array
    {
        $n = max(1, count($values) - 1);
        $out = [];
        foreach (array_values($values) as $i => $v) {
            $out[] = [round($padX + $i * ($w - 2 * $padX) / $n, 2), round($h - ($max > 0 ? $v / $max : 0) * $h, 2)];
        }

        return $out;
    }

    /** Smooth (Catmull-Rom → Bézier) path through points, clamped to the plot so it never dips below zero. */
    public static function smoothPath(array $pts, float $h, float $tension = 0.12): string
    {
        if (! $pts) return '';
        $d = 'M'.$pts[0][0].','.$pts[0][1];
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $pts[max(0, $i - 1)];
            $p1 = $pts[$i];
            $p2 = $pts[$i + 1];
            $p3 = $pts[min($n - 1, $i + 2)];
            $c1 = [$p1[0] + ($p2[0] - $p0[0]) * $tension, min($h, max(0, $p1[1] + ($p2[1] - $p0[1]) * $tension))];
            $c2 = [$p2[0] - ($p3[0] - $p1[0]) * $tension, min($h, max(0, $p2[1] - ($p3[1] - $p1[1]) * $tension))];
            $d .= sprintf(' C%.2f,%.2f %.2f,%.2f %.2f,%.2f', $c1[0], $c1[1], $c2[0], $c2[1], $p2[0], $p2[1]);
        }

        return $d;
    }

    /** Percent change, or null when there is no baseline. */
    public static function delta(int $current, int $previous): ?float
    {
        if ($previous === 0) return null;

        return round(($current - $previous) / abs($previous) * 100, 1);
    }
}
