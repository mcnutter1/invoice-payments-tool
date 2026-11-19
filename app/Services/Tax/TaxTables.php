<?php

namespace App\Services\Tax;

use App\Exceptions\DomainException;
use App\Models\TaxTable;
use App\Services\Audit;

/** Resolves tax parameters per jurisdiction and year: built-in defaults, overridden by synced or manually entered rows. */
class TaxTables
{
    /** Indexed (year-specific) keys; when they come from an earlier year the report warns that they are carried forward. */
    private const INDEXED = ['brackets', 'standard_deduction', 'ss_wage_base', 'qbi', 'child_tax_credit', 'section_179', 'personal_exemption', 'corporate'];

    private const KNOWN = ['type', 'name', 'brackets', 'status_map', 'standard_deduction', 'personal_exemption', 'ss_wage_base', 'se', 'fica',
        'additional_medicare', 'futa', 'corporate_rate', 'meals_deductible', 'home_office_simplified', 'estimated', 'qbi', 'child_tax_credit',
        'section_179', 'allows_se_tax_deduction', 'allows_retirement_deduction', 'corporate', 'partnership_fee', 'flat_rate', 'sources'];

    private array $rows = [];

    /**
     * @return array{jurisdiction:string, year:int, data:array, carried:list<string>, layers:list<array{year:int, source:string, notes:?string, url:?string}>}|null
     */
    public function resolve(string $jurisdiction, int $year): ?array
    {
        $j = strtoupper($jurisdiction);
        $builtin = TaxTableDefaults::all()[$j] ?? [];
        $db = $this->rows[$j] ??= TaxTable::where('jurisdiction', $j)->get()->keyBy('year');
        $years = collect(array_keys($builtin))->merge($db->keys())->unique()->filter(fn ($y) => $y <= $year)->sort()->values();
        if ($years->isEmpty()) return null;

        $data = [];
        $exact = [];
        $layers = [];
        foreach ($years as $y) {
            $layer = $builtin[$y] ?? [];
            if (isset($builtin[$y])) $layers[] = ['year' => $y, 'source' => 'built-in', 'notes' => null, 'url' => null];
            if ($row = $db[$y] ?? null) {
                $layer = self::merge($layer, $row->data);
                $layers[] = ['year' => $y, 'source' => $row->source, 'notes' => $row->notes, 'url' => $row->source_url, 'updated_at' => $row->updated_at];
            }
            $data = self::merge($data, $layer);
            if ($y === $year) $exact = array_keys($layer);
        }
        $carried = array_values(array_intersect(self::INDEXED, array_diff(array_keys($data), $exact)));

        return ['jurisdiction' => $j, 'year' => $year, 'data' => $data, 'carried' => $carried, 'layers' => $layers];
    }

    /** Every jurisdiction with built-in or stored parameters. */
    public function jurisdictions(): array
    {
        return collect(array_keys(TaxTableDefaults::all()))->merge(TaxTable::distinct()->pluck('jurisdiction'))->unique()->sort()->values()->all();
    }

    public function validate(string $jurisdiction, array $data): array
    {
        $errors = [];
        foreach (array_diff(array_keys($data), self::KNOWN) as $k) $errors[] = "Unknown key \"{$k}\".";
        $checkBrackets = function (string $path, $list) use (&$errors) {
            if (! is_array($list) || ! array_is_list($list) || ! $list) { $errors[] = "{$path} must be a non-empty list of [floor, rate]."; return; }
            $prev = -1;
            foreach ($list as $i => $b) {
                if (! is_array($b) || count($b) !== 2 || ! is_numeric($b[0]) || ! is_numeric($b[1])) { $errors[] = "{$path}[{$i}] must be [floor, rate]."; continue; }
                if ($i === 0 && (float) $b[0] !== 0.0) $errors[] = "{$path} must start at 0.";
                if ($b[0] <= $prev) $errors[] = "{$path} floors must increase.";
                if ($b[1] < 0 || $b[1] > 0.6) $errors[] = "{$path}[{$i}] rate must be a decimal between 0 and 0.6 (e.g. 0.22).";
                $prev = $b[0];
            }
        };
        foreach ((array) ($data['brackets'] ?? []) as $status => $list) {
            if (! in_array($status, ['single', 'mfj', 'mfs', 'hoh'], true)) $errors[] = "brackets.{$status} is not a filing status (single, mfj, mfs, hoh).";
            $checkBrackets("brackets.{$status}", $list);
        }
        if (isset($data['corporate']['tiers'])) $checkBrackets('corporate.tiers', $data['corporate']['tiers']);
        foreach ((array) ($data['standard_deduction'] ?? []) as $status => $v) {
            if (! is_numeric($v) || $v < 0 || $v > 200000) $errors[] = "standard_deduction.{$status} looks wrong.";
        }
        if (isset($data['ss_wage_base']) && (! is_numeric($data['ss_wage_base']) || $data['ss_wage_base'] < 50000 || $data['ss_wage_base'] > 1000000)) {
            $errors[] = 'ss_wage_base must be a dollar amount between 50,000 and 1,000,000.';
        }
        foreach (['corporate_rate', 'flat_rate', 'meals_deductible'] as $k) {
            if (isset($data[$k]) && (! is_numeric($data[$k]) || $data[$k] < 0 || $data[$k] > 1)) $errors[] = "{$k} must be a decimal between 0 and 1.";
        }
        if (isset($data['type']) && ! in_array($data['type'], ['federal', 'progressive', 'flat', 'none'], true)) $errors[] = 'type must be federal, progressive, flat or none.';
        if (strtoupper($jurisdiction) !== 'US' && ($data['type'] ?? null) === 'federal') $errors[] = 'Only US can use type "federal".';

        return $errors;
    }

    /** Store (merging into any existing row for that year) after validation. */
    public function save(string $jurisdiction, int $year, array $data, string $source, ?string $url = null, ?string $notes = null, ?int $userId = null): TaxTable
    {
        $j = strtoupper($jurisdiction);
        if (! preg_match('/^[A-Z]{2,10}$/', $j) || $year < 2018 || $year > 2100) throw new DomainException('Use a jurisdiction like US or NJ and a four-digit year.');
        if ($errors = $this->validate($j, $data)) throw new DomainException('Tax table rejected: '.implode(' ', $errors));
        $row = TaxTable::firstOrNew(['jurisdiction' => $j, 'year' => $year]);
        $before = $row->exists ? $row->data : null;
        $row->fill(['data' => self::merge($row->data ?? [], $data), 'source' => $source, 'source_url' => $url, 'notes' => $notes, 'updated_by' => $userId]);
        $row->save();
        unset($this->rows[$j]);
        Audit::log(null, 'tax_table.saved', $row, $before, $data, $notes, $userId ? null : 'system');

        return $row;
    }

    /** Deep merge where assoc arrays merge by key and lists (brackets) are replaced whole. */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = is_array($v) && ! array_is_list($v) && is_array($a[$k] ?? null) && ! array_is_list($a[$k]) ? self::merge($a[$k], $v) : $v;
        }

        return $a;
    }
}
