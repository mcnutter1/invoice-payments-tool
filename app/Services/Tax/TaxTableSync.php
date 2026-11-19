<?php

namespace App\Services\Tax;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Keeps tax parameters current from official publications:
 *  - irs.gov "Federal income tax rates and brackets" (all filing statuses, current year) and the linked
 *    inflation-adjustment release for the next year (single/joint brackets and standard deductions);
 *  - ssa.gov contribution and benefit base (Social Security wage base);
 *  - an optional JSON feed (FINANCE_TAX_FEED_URL) for state tables and anything not published in a parseable form.
 * Every change is validated, stored as a tax_tables row and audited; nothing is applied silently.
 */
class TaxTableSync
{
    public const IRS_RATES_URL = 'https://www.irs.gov/filing/federal-income-tax-rates-and-brackets';
    public const SSA_BASE_URL = 'https://www.ssa.gov/oact/cola/cbb.html';

    public function __construct(private readonly TaxTables $tables) {}

    /** @return array{ok:bool, changes:list<string>, errors:list<string>, at:string} */
    public function sync(): array
    {
        $changes = [];
        $errors = [];
        foreach (['irs' => fn () => $this->syncIrs(), 'ssa' => fn () => $this->syncSsa(), 'feed' => fn () => $this->syncFeed()] as $name => $run) {
            try {
                array_push($changes, ...$run());
            } catch (Throwable $e) {
                $errors[] = $name.': '.mb_substr($e->getMessage(), 0, 300);
            }
        }
        $result = ['ok' => ! $errors, 'changes' => $changes, 'errors' => $errors, 'at' => now()->toIso8601String()];
        PlatformSetting::write('tax_tables.last_sync', $result);

        return $result;
    }

    public function syncIrs(): array
    {
        $html = $this->get(self::IRS_RATES_URL);
        $changes = [];
        $current = $this->parseIrsRatesPage($html);
        if ($current) {
            $changes = array_merge($changes, $this->apply('US', $current['year'], ['brackets' => $current['brackets']], 'irs.gov', self::IRS_RATES_URL, null));
        }
        if (preg_match('#Find the (20\d{2})\s*<a[^>]+href="([^"]+)"#i', $html, $m)) {
            $url = str_starts_with($m[2], 'http') ? $m[2] : 'https://www.irs.gov'.$m[2];
            $next = $this->parseIrsRelease($this->get($url), (int) $m[1]);
            if ($next) {
                $changes = array_merge($changes, $this->apply('US', $next['year'], $next['data'], 'irs.gov', $url,
                    'From the IRS inflation-adjustment release. Head-of-household brackets, QBI thresholds and other indexed amounts not in the release carry forward until reviewed.'));
            }
        }

        return $changes;
    }

    public function syncSsa(): array
    {
        $text = $this->text($this->get(self::SSA_BASE_URL));
        if (! preg_match('/For earnings in (20\d{2}), this base is \$([\d,]+)/i', $text, $m)) throw new \RuntimeException('Wage base not found on ssa.gov page.');

        return $this->apply('US', (int) $m[1], ['ss_wage_base' => (int) str_replace(',', '', $m[2])], 'ssa.gov', self::SSA_BASE_URL, null);
    }

    public function syncFeed(): array
    {
        $url = (string) config('finance.tax.feed_url');
        if ($url === '') return [];
        if (! str_starts_with($url, 'https://')) throw new \RuntimeException('Tax feed URL must use HTTPS.');
        $json = json_decode($this->get($url), true);
        if (! is_array($json['tables'] ?? null)) throw new \RuntimeException('Feed must be {"tables": [{"jurisdiction", "year", "data"}]}.');
        $changes = [];
        foreach ($json['tables'] as $t) {
            $changes = array_merge($changes, $this->apply((string) ($t['jurisdiction'] ?? ''), (int) ($t['year'] ?? 0), (array) ($t['data'] ?? []), 'feed', $url, $t['notes'] ?? null));
        }

        return $changes;
    }

    /** @return array{year:int, brackets:array}|null */
    public function parseIrsRatesPage(string $html): ?array
    {
        if (! preg_match('/(20\d{2}) tax rates for a single taxpayer/i', $html, $y)) return null;
        $statuses = ['single' => 'tax rates for a single taxpayer', 'mfj' => 'Married filing jointly', 'mfs' => 'Married filing separately', 'hoh' => 'Head of household'];
        $brackets = [];
        foreach ($statuses as $key => $heading) {
            $pos = stripos($html, $heading);
            if ($pos === false || ! preg_match('#<table.*?</table>#is', substr($html, $pos), $t)) continue;
            $rows = [];
            preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $t[0], $trs);
            foreach ($trs[1] as $tr) {
                preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $tr, $cells);
                $c = array_map(fn ($x) => $this->text($x), $cells[1]);
                if (count($c) < 2 || ! preg_match('/^(\d+(?:\.\d+)?)%$/', $c[0], $r) || ! preg_match('/\$?([\d,]+)/', $c[1], $from)) continue;
                $rows[] = [(int) str_replace(',', '', $from[1]), round($r[1] / 100, 4)];
            }
            // The IRS lists the first dollar of each band (e.g. $11,926); floors are stored as the prior band's top.
            $rows = array_map(fn ($b) => [$b[0] > 0 ? $b[0] - 1 : 0, $b[1]], $rows);
            if (count($rows) >= 5) $brackets[$key] = $rows;
        }

        return isset($brackets['single']) ? ['year' => (int) $y[1], 'brackets' => $brackets] : null;
    }

    /** @return array{year:int, data:array}|null */
    public function parseIrsRelease(string $html, int $year): ?array
    {
        $t = $this->text($html);
        $n = fn ($s) => (int) str_replace(',', '', $s);
        $data = [];
        if (preg_match('/standard deduction increases to \$([\d,]+) for married couples filing jointly.*?single taxpayers and married individuals filing separately, the standard deduction rises to \$([\d,]+).*?heads of households, the standard deduction will be \$([\d,]+)/is', $t, $m)) {
            $data['standard_deduction'] = ['mfj' => $n($m[1]), 'single' => $n($m[2]), 'mfs' => $n($m[2]), 'hoh' => $n($m[3])];
        }
        $single = [[0, 0.10]];
        $mfj = [[0, 0.10]];
        if (preg_match('/37% for individual single taxpayers with incomes greater than \$([\d,]+) \(\$([\d,]+) for married couples filing jointly\)/i', $t, $m)) {
            $single[] = [$n($m[1]), 0.37];
            $mfj[] = [$n($m[2]), 0.37];
        }
        preg_match_all('/(\d{2})% for incomes over \$([\d,]+) \(\$([\d,]+) for married couples filing jointly\)/i', $t, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $single[] = [$n($m[2]), $m[1] / 100];
            $mfj[] = [$n($m[3]), $m[1] / 100];
        }
        if (count($single) === 7) {
            usort($single, fn ($a, $b) => $a[0] <=> $b[0]);
            usort($mfj, fn ($a, $b) => $a[0] <=> $b[0]);
            // Married-filing-separately thresholds are half the joint thresholds.
            $data['brackets'] = ['single' => $single, 'mfj' => $mfj, 'mfs' => array_map(fn ($b) => [(int) round($b[0] / 2), $b[1]], $mfj)];
        }

        return $data ? ['year' => $year, 'data' => $data] : null;
    }

    /** Store only values that differ from what is already in effect. */
    private function apply(string $j, int $year, array $data, string $source, string $url, ?string $notes): array
    {
        $current = $this->tables->resolve($j, $year);
        $diff = [];
        foreach ($data as $k => $v) {
            $existing = $current['data'][$k] ?? null;
            $sameYear = $current && ! in_array($k, $current['carried'], true);
            $merged = is_array($v) && is_array($existing) ? TaxTables::merge($existing, $v) : $v;
            if (! $sameYear || $merged != $existing) $diff[$k] = $v;
        }
        if (! $diff) return [];
        $this->tables->save($j, $year, $diff, $source, $url, $notes);

        return array_map(fn ($k) => "{$j} {$year} {$k}", array_keys($diff));
    }

    private function get(string $url): string
    {
        $res = Http::timeout(20)->withHeaders(['User-Agent' => 'McNuttFinance/1.0 (tax table check)'])->get($url);
        if (! $res->successful()) throw new \RuntimeException(parse_url($url, PHP_URL_HOST).' returned HTTP '.$res->status());

        return $res->body();
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }
}
