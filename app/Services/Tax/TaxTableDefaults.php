<?php

namespace App\Services\Tax;

/**
 * Built-in tax parameters shipped with the app. Dollar amounts are whole dollars; rates are decimals.
 * Brackets are [floor, marginal rate] pairs. Rows in `tax_tables` override any key for a jurisdiction-year.
 */
final class TaxTableDefaults
{
    /** States without a broad personal income tax on wages/business income. */
    public const NO_INCOME_TAX = ['AK', 'FL', 'NV', 'NH', 'SD', 'TN', 'TX', 'WA', 'WY'];

    /** Statutory federal items that are not inflation-indexed. */
    private static function federalStatutory(): array
    {
        return [
            'type' => 'federal',
            'se' => ['net_earnings_factor' => 0.9235, 'ss_rate' => 0.124, 'medicare_rate' => 0.029, 'min_net_earnings' => 400, 'deductible_share' => 0.5],
            'fica' => ['ss_rate' => 0.062, 'medicare_rate' => 0.0145],
            'additional_medicare' => ['rate' => 0.009, 'threshold' => ['single' => 200000, 'mfj' => 250000, 'mfs' => 125000, 'hoh' => 200000]],
            'futa' => ['rate' => 0.006, 'wage_base' => 7000],
            'corporate_rate' => 0.21,
            'meals_deductible' => 0.5,
            'home_office_simplified' => ['rate' => 5, 'max_sqft' => 300],
            'estimated' => ['current_year_pct' => 0.90, 'prior_year_pct' => 1.00, 'high_agi' => ['single' => 150000, 'mfj' => 150000, 'mfs' => 75000, 'hoh' => 150000],
                'high_agi_prior_year_pct' => 1.10, 'threshold' => 1000, 'due' => ['04-15', '06-15', '09-15', '+01-15'], 'corporate_due' => ['04-15', '06-15', '09-15', '12-15']],
        ];
    }

    public static function all(): array
    {
        $us2025 = self::federalStatutory() + [
            'brackets' => [
                'single' => [[0, 0.10], [11925, 0.12], [48475, 0.22], [103350, 0.24], [197300, 0.32], [250525, 0.35], [626350, 0.37]],
                'mfj' => [[0, 0.10], [23850, 0.12], [96950, 0.22], [206700, 0.24], [394600, 0.32], [501050, 0.35], [751600, 0.37]],
                'mfs' => [[0, 0.10], [11925, 0.12], [48475, 0.22], [103350, 0.24], [197300, 0.32], [250525, 0.35], [375800, 0.37]],
                'hoh' => [[0, 0.10], [17000, 0.12], [64850, 0.22], [103350, 0.24], [197300, 0.32], [250500, 0.35], [626350, 0.37]],
            ],
            'standard_deduction' => ['single' => 15750, 'mfj' => 31500, 'mfs' => 15750, 'hoh' => 23625],
            'ss_wage_base' => 176100,
            'qbi' => ['rate' => 0.20, 'threshold' => ['single' => 197300, 'mfj' => 394600, 'mfs' => 197300, 'hoh' => 197300],
                'phase_in' => ['single' => 50000, 'mfj' => 100000, 'mfs' => 50000, 'hoh' => 50000], 'min_deduction' => 0, 'min_qbi' => 0],
            'child_tax_credit' => ['amount' => 2200, 'phaseout' => ['single' => 200000, 'mfj' => 400000, 'mfs' => 200000, 'hoh' => 200000], 'reduction_per_1000' => 50],
            'section_179' => ['limit' => 2500000, 'phaseout' => 4000000],
            'sources' => ['Rev. Proc. 2024-40', 'One Big Beautiful Bill Act (P.L. 119-21) standard deduction, CTC and §179 amounts', 'SSA contribution and benefit base'],
        ];
        $us2026 = self::federalStatutory() + [
            'brackets' => [
                'single' => [[0, 0.10], [12400, 0.12], [50400, 0.22], [105700, 0.24], [201775, 0.32], [256225, 0.35], [640600, 0.37]],
                'mfj' => [[0, 0.10], [24800, 0.12], [100800, 0.22], [211400, 0.24], [403550, 0.32], [512450, 0.35], [768700, 0.37]],
                'mfs' => [[0, 0.10], [12400, 0.12], [50400, 0.22], [105700, 0.24], [201775, 0.32], [256225, 0.35], [384350, 0.37]],
                'hoh' => [[0, 0.10], [17700, 0.12], [67450, 0.22], [105700, 0.24], [201750, 0.32], [256200, 0.35], [640600, 0.37]],
            ],
            'standard_deduction' => ['single' => 16100, 'mfj' => 32200, 'mfs' => 16100, 'hoh' => 24150],
            'ss_wage_base' => 184500,
            'qbi' => ['rate' => 0.20, 'threshold' => ['single' => 201750, 'mfj' => 403500, 'mfs' => 201750, 'hoh' => 201750],
                'phase_in' => ['single' => 75000, 'mfj' => 150000, 'mfs' => 75000, 'hoh' => 75000], 'min_deduction' => 400, 'min_qbi' => 1000],
            'child_tax_credit' => ['amount' => 2200, 'phaseout' => ['single' => 200000, 'mfj' => 400000, 'mfs' => 200000, 'hoh' => 200000], 'reduction_per_1000' => 50],
            'section_179' => ['limit' => 2560000, 'phaseout' => 4090000],
            'sources' => ['Rev. Proc. 2025-32 (IR-2025-103)', 'One Big Beautiful Bill Act (P.L. 119-21)', 'SSA contribution and benefit base'],
        ];
        $nj = [
            'type' => 'progressive',
            'name' => 'New Jersey Gross Income Tax',
            'brackets' => [
                'single' => [[0, 0.014], [20000, 0.0175], [35000, 0.035], [40000, 0.05525], [75000, 0.0637], [500000, 0.0897], [1000000, 0.1075]],
                'mfj' => [[0, 0.014], [20000, 0.0175], [50000, 0.0245], [70000, 0.035], [80000, 0.05525], [150000, 0.0637], [500000, 0.0897], [1000000, 0.1075]],
            ],
            'status_map' => ['mfs' => 'single', 'hoh' => 'mfj'],
            'standard_deduction' => ['single' => 0, 'mfj' => 0, 'mfs' => 0, 'hoh' => 0],
            'personal_exemption' => ['taxpayer' => 1000, 'spouse' => 1000, 'dependent' => 1500],
            // NJ computes net profits under its own rules: no deduction for half of federal SE tax.
            'allows_se_tax_deduction' => false,
            'allows_retirement_deduction' => true,
            'corporate' => [
                'name' => 'NJ Corporation Business Tax',
                'tiers' => [[0, 0.065], [50001, 0.075], [100001, 0.09]],
                'surtax' => ['threshold' => 10000000, 'rate' => 0.025, 'label' => 'Corporate Transit Fee'],
                'minimum_tax' => [[0, 500], [100000, 750], [250000, 1000], [500000, 1500], [1000000, 2000]],
                's_corp_minimum_tax' => [[0, 375], [100000, 562.5], [250000, 750], [500000, 937.5], [1000000, 1500]],
            ],
            'partnership_fee' => ['per_owner' => 150, 'min_owners' => 3],
            'estimated' => ['current_year_pct' => 0.80, 'prior_year_pct' => 1.00, 'threshold' => 400, 'due' => ['04-15', '06-15', '09-15', '+01-15']],
            'sources' => ['NJ-1040 Tax Rate Schedules', 'N.J.S.A. 54A:2-1', 'NJ CBT-100 / CBT-100S instructions'],
        ];

        $out = ['US' => [2025 => $us2025, 2026 => $us2026], 'NJ' => [2025 => $nj, 2026 => $nj]];
        foreach (self::NO_INCOME_TAX as $st) {
            $out[$st] = [2025 => ['type' => 'none', 'name' => $st.' (no personal income tax)'], 2026 => ['type' => 'none', 'name' => $st.' (no personal income tax)']];
        }

        return $out;
    }
}
