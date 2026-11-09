<?php

namespace App\Services\Expenses;

use App\Models\MileageRate;
use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Keeps IRS standard mileage rates current by reading the IRS rates table. */
class MileageRateSync
{
    public const URL = 'https://www.irs.gov/tax-professionals/standard-mileage-rates';

    /** @return array{ok: bool, added: list<string>, error: ?string} */
    public function sync(): array
    {
        try {
            $res = Http::timeout(20)->withHeaders(['User-Agent' => 'McNuttFinance/1.0 (mileage rate check)'])->get(self::URL);
            if (! $res->successful()) throw new \RuntimeException('IRS page returned HTTP '.$res->status());
            $added = [];
            foreach ($this->parse($res->body()) as [$from, $millis]) {
                if (MileageRate::whereDate('effective_from', $from)->exists()) continue;
                MileageRate::create(['effective_from' => $from, 'business_rate_millis' => $millis, 'source' => 'irs.gov', 'note' => 'From '.self::URL]);
                $added[] = $from.' @ '.($millis / 10).'¢';
            }
            $result = ['ok' => true, 'added' => $added, 'error' => null];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'added' => [], 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        PlatformSetting::write('mileage.last_sync', $result + ['at' => now()->toIso8601String()]);

        return $result;
    }

    /** @return list<array{0: string, 1: int}> [effective_from Y-m-d, business rate in tenths of a cent] */
    public function parse(string $html): array
    {
        $out = [];
        preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $row, $cells);
            $cells = array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($c)))), $cells[1]);
            if (count($cells) < 2 || ! preg_match('/\b(20\d{2})\b/', $cells[0], $y)) continue;
            $from = preg_match('/\bJul/i', $cells[0]) ? $y[1].'-07-01' : $y[1].'-01-01';
            if (! preg_match('/(\d+(?:\.\d+)?)/', str_replace('$0.', '0.', $cells[1]), $n)) continue;
            $cents = (float) $n[1];
            if ($cents < 2) $cents *= 100; // "$0.70" style
            if ($cents < 30 || $cents > 200) continue;
            $out[] = [Carbon::parse($from)->toDateString(), (int) round($cents * 10)];
        }

        return $out;
    }
}
