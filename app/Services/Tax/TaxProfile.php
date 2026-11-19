<?php

namespace App\Services\Tax;

use App\Models\Business;

/** Owner/entity parameters for the tax estimate, stored on businesses.tax_profile (year-specific values under years.{YYYY}). */
final class TaxProfile
{
    public const ENTITIES = [
        'sole_prop' => ['Sole proprietorship', 'Form 1040 · Schedule C · Schedule SE'],
        'smllc' => ['Single-member LLC (disregarded entity)', 'Form 1040 · Schedule C · Schedule SE'],
        'partnership' => ['Partnership / multi-member LLC', 'Form 1065 · Schedule K-1 · owner Form 1040 + Schedule SE'],
        's_corp' => ['S corporation (or LLC taxed as S corp)', 'Form 1120-S · Schedule K-1 · W-2 · owner Form 1040'],
        'c_corp' => ['C corporation (or LLC taxed as C corp)', 'Form 1120'],
    ];

    public const FILING_STATUSES = ['single' => 'Single', 'mfj' => 'Married filing jointly', 'mfs' => 'Married filing separately', 'hoh' => 'Head of household'];

    /** Shared across years. */
    private const GENERAL = [
        'entity' => null, 'filing_status' => 'single', 'state' => null, 'tax_method' => 'cash', 'ownership_pct' => 100, 'owner_count' => 1,
        'sstb' => true, 'partner_se' => true, 'dependents' => 0, 'home_office_sqft' => 0, 'section_179' => true,
        'include_open_invoices' => true, 'include_recurring' => true, 'project_expenses' => false, 'doubtful_after_days' => 90,
    ];

    /** Per tax year; money in cents. */
    private const YEARLY = [
        'officer_salary' => 0, 'salary_in_books' => false, 'other_wages' => 0, 'spouse_wages' => 0, 'other_income' => 0,
        'retirement' => 0, 'health_insurance' => 0, 'itemized' => 0, 'w2_wages_paid' => 0,
        'fed_withholding' => 0, 'state_withholding' => 0, 'fed_estimates_paid' => 0, 'state_estimates_paid' => 0,
        'prior_year_fed_tax' => 0, 'prior_year_state_tax' => 0, 'prior_year_agi' => 0,
    ];

    public static function get(Business $b, int $year): array
    {
        $p = (array) ($b->tax_profile ?? []);
        $general = array_replace(self::GENERAL, array_intersect_key($p, self::GENERAL));
        $general['entity'] ??= self::guessEntity($b);
        $general['state'] = strtoupper($general['state'] ?: ($b->state ?: 'NJ'));
        $yearly = array_replace(self::YEARLY, array_intersect_key((array) ($p['years'][$year] ?? []), self::YEARLY));

        return $general + $yearly + ['configured' => isset($p['entity'])];
    }

    public static function save(Business $b, int $year, array $general, array $yearly): void
    {
        $p = (array) ($b->tax_profile ?? []);
        $p = array_replace($p, array_intersect_key($general, self::GENERAL));
        $p['years'][$year] = array_replace((array) ($p['years'][$year] ?? []), array_intersect_key($yearly, self::YEARLY));
        $b->forceFill(['tax_profile' => $p])->save();
    }

    public static function yearlyKeys(): array
    {
        return array_keys(self::YEARLY);
    }

    private static function guessEntity(Business $b): string
    {
        $w9 = (array) ($b->w9_settings ?? []);
        $byW9 = ['individual' => 'sole_prop', 'disregarded' => 'smllc', 's_corp' => 's_corp', 'c_corp' => 'c_corp', 'partnership' => 'partnership'];
        if (isset($byW9[$w9['classification'] ?? ''])) return $byW9[$w9['classification']];
        if (($w9['classification'] ?? '') === 'llc') return ['S' => 's_corp', 'C' => 'c_corp'][$w9['llc_code'] ?? ''] ?? 'partnership';
        $t = strtolower((string) $b->entity_type);

        return match (true) {
            str_contains($t, 's corp') || str_contains($t, 's_corp') || str_contains($t, 's-corp') => 's_corp',
            str_contains($t, 'c corp') || str_contains($t, 'c_corp') || str_contains($t, 'inc') || str_contains($t, 'corporation') => 'c_corp',
            str_contains($t, 'partner') => 'partnership',
            str_contains($t, 'llc') => 'smllc',
            default => 'sole_prop',
        };
    }
}
