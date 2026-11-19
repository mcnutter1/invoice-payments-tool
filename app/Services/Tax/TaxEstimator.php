<?php

namespace App\Services\Tax;

use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\RecurringSchedule;
use App\Services\Recurring\RecurrenceCalculator;
use App\Services\Reports\ReportService;
use Illuminate\Support\Carbon;

/**
 * Estimates the year's federal and state income tax (plus self-employment / payroll tax) from the books.
 * All amounts are integer cents; every computed line carries its formula and the return line it feeds.
 * Not modeled: capital gains, AMT, NIIT, credits other than the child tax credit, local taxes.
 */
class TaxEstimator
{
    /** Expense categories → return line per form. */
    private const LINES = [
        'gross' => ['schedule_c' => '1 Gross receipts', 'f1065' => '1a Gross receipts', 'f1120s' => '1a Gross receipts', 'f1120' => '1a Gross receipts'],
        'returns' => ['schedule_c' => '2 Returns and allowances', 'f1065' => '1b Returns and allowances', 'f1120s' => '1b Returns and allowances', 'f1120' => '1b Returns and allowances'],
        'cogs' => ['schedule_c' => '4 Cost of goods sold', 'f1065' => '2 Cost of goods sold', 'f1120s' => '2 Cost of goods sold', 'f1120' => '2 Cost of goods sold'],
        'advertising' => ['schedule_c' => '8 Advertising', 'f1065' => '20 Other deductions', 'f1120s' => '16 Advertising', 'f1120' => '22 Advertising'],
        'vehicle' => ['schedule_c' => '9 Car and truck expenses', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'commissions' => ['schedule_c' => '10 Commissions and fees', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'contract_labor' => ['schedule_c' => '11 Contract labor', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'depreciation' => ['schedule_c' => '13 Depreciation and section 179', 'f1065' => '16a Depreciation', 'f1120s' => '14 Depreciation', 'f1120' => '20 Depreciation'],
        'insurance' => ['schedule_c' => '15 Insurance (other than health)', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'interest' => ['schedule_c' => '16b Interest (other)', 'f1065' => '15 Interest', 'f1120s' => '13 Interest', 'f1120' => '18 Interest'],
        'professional' => ['schedule_c' => '17 Legal and professional services', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'office' => ['schedule_c' => '18 Office expense', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'rent' => ['schedule_c' => '20b Rent — other business property', 'f1065' => '13 Rent', 'f1120s' => '11 Rents', 'f1120' => '16 Rents'],
        'repairs' => ['schedule_c' => '21 Repairs and maintenance', 'f1065' => '11 Repairs and maintenance', 'f1120s' => '9 Repairs and maintenance', 'f1120' => '14 Repairs and maintenance'],
        'taxes' => ['schedule_c' => '23 Taxes and licenses', 'f1065' => '14 Taxes and licenses', 'f1120s' => '12 Taxes and licenses', 'f1120' => '17 Taxes and licenses'],
        'travel' => ['schedule_c' => '24a Travel', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'meals' => ['schedule_c' => '24b Deductible meals', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'utilities' => ['schedule_c' => '25 Utilities', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
        'wages' => ['schedule_c' => '26 Wages', 'f1065' => '9 Salaries and wages', 'f1120s' => '8 Salaries and wages', 'f1120' => '13 Salaries and wages'],
        'officer_comp' => ['schedule_c' => '26 Wages', 'f1065' => '10 Guaranteed payments', 'f1120s' => '7 Compensation of officers', 'f1120' => '12 Compensation of officers'],
        'payroll_taxes' => ['schedule_c' => '23 Taxes and licenses', 'f1065' => '14 Taxes and licenses', 'f1120s' => '12 Taxes and licenses', 'f1120' => '17 Taxes and licenses'],
        'bad_debt' => ['schedule_c' => '27a Other expenses (bad debts)', 'f1065' => '12 Bad debts', 'f1120s' => '10 Bad debts', 'f1120' => '15 Bad debts'],
        'home_office' => ['schedule_c' => '30 Business use of home (simplified)', 'f1065' => '—', 'f1120s' => '—', 'f1120' => '—'],
        'other' => ['schedule_c' => '27a Other expenses', 'f1065' => '20 Other deductions', 'f1120s' => '19 Other deductions', 'f1120' => '26 Other deductions'],
    ];

    private const CATEGORY_PATTERNS = [
        'meals' => '/meal|entertain/i', 'advertising' => '/advertis|marketing/i', 'vehicle' => '/vehicle|mileage|car |auto|fuel/i',
        'commissions' => '/commission/i', 'contract_labor' => '/contract labor|subcontract|freelanc/i', 'depreciation' => '/depreciat|amortiz/i',
        'insurance' => '/insurance/i', 'interest' => '/interest/i', 'professional' => '/legal|professional|accounting|bookkeep/i',
        'rent' => '/rent|lease/i', 'repairs' => '/repair|maintenance/i', 'payroll_taxes' => '/payroll tax|employer tax/i', 'officer_comp' => '/officer/i',
        'wages' => '/wage|salar|payroll/i', 'taxes' => '/tax|licen|permit/i', 'travel' => '/travel/i',
        'utilities' => '/utilit|phone|internet|telecom/i', 'office' => '/office|software|supplies|subscription|postage/i',
    ];

    public function __construct(private readonly ReportService $reports, private readonly TaxTables $tables, private readonly RecurrenceCalculator $calc) {}

    public function estimate(Business $b, int $year, ?array $profile = null): array
    {
        $p = $profile ?? TaxProfile::get($b, $year);
        $today = $b->localToday();
        $from = Carbon::create($year, 1, 1);
        $yearEnd = Carbon::create($year, 12, 31);
        $asOf = $today->lt($yearEnd) ? $today->copy() : $yearEnd->copy();
        $projecting = $today->lt($yearEnd) && $today->year === $year;
        $warnings = [];

        $us = $this->tables->resolve('US', $year) ?? throw new \RuntimeException('No federal tax tables available.');
        if ($us['carried']) $warnings[] = 'Federal '.$year.' values not yet published or loaded for: '.implode(', ', $us['carried']).' — using the most recent year available. Update them under Platform → Tax tables.';
        $st = $this->tables->resolve($p['state'], $year);
        if (! $st) $warnings[] = 'No tax table is loaded for '.$p['state'].'; state tax is not estimated. Add one under Platform → Tax tables.';
        elseif ($st['carried'] && ($st['data']['type'] ?? '') !== 'none') $warnings[] = $p['state'].' '.$year.' values carried forward from an earlier year for: '.implode(', ', $st['carried']).'.';
        $t = $us['data'];
        $fs = $p['filing_status'];
        $form = match ($p['entity']) { 'partnership' => 'f1065', 's_corp' => 'f1120s', 'c_corp' => 'f1120', default => 'schedule_c' };

        // 1. Books → tax-basis business income.
        $books = $this->books($b, $from->toDateString(), $asOf->toDateString(), $p['tax_method'], $form, $t);
        $projection = $projecting ? $this->projection($b, $asOf, $yearEnd, $p, $books) : null;
        $adj = $this->adjustments($b, $year, $p, $books, $t);
        $grossReceipts = $books['income'] + ($projection['income'] ?? 0);
        $expenses = $books['expense'] + ($projection['expenses'] ?? 0);
        $netBeforeOwner = $grossReceipts - $books['cogs'] - $expenses + $adj['total'];

        // Owner compensation and the employer's share of payroll taxes (corporations only).
        $payroll = null;
        if (in_array($p['entity'], ['s_corp', 'c_corp'], true)) {
            $payroll = $this->payroll($p['officer_salary'], $t);
            if ($p['officer_salary'] > 0 && ! $p['salary_in_books']) {
                $adj['lines'][] = $this->line('Officer compensation (not yet in the books)', -$p['officer_salary'], 'Reasonable salary entered in tax settings', self::LINES['officer_comp'][$form]);
                $adj['lines'][] = $this->line('Employer payroll taxes on officer salary', -$payroll['employer'], 'Employer Social Security + Medicare + FUTA', self::LINES['payroll_taxes'][$form]);
                $netBeforeOwner -= $p['officer_salary'] + $payroll['employer'];
            }
        }
        $homeOffice = 0;
        if (in_array($p['entity'], ['sole_prop', 'smllc'], true) && $p['home_office_sqft'] > 0) {
            $ho = $t['home_office_simplified'];
            $homeOffice = min((int) min($p['home_office_sqft'], $ho['max_sqft']) * $ho['rate'] * 100, max(0, $netBeforeOwner));
            $adj['lines'][] = $this->line('Home office (simplified method)', -$homeOffice, min($p['home_office_sqft'], $ho['max_sqft']).' sq ft × $'.$ho['rate'].' (max '.$ho['max_sqft'].' sq ft), limited to business income', self::LINES['home_office'][$form]);
            $netBeforeOwner -= $homeOffice;
        }
        $net = $netBeforeOwner;

        $business = [
            $this->line('Gross receipts recorded '.$from->format('M j').' – '.$asOf->format('M j, Y'), $books['income'], ucfirst($p['tax_method']).'-basis income accounts', self::LINES['gross'][$form]),
        ];
        if ($projection) {
            $business[] = $this->line('Expected receipts through Dec 31', $projection['income'], 'Open invoices + scheduled recurring invoices (pre-sales-tax), see Projection', self::LINES['gross'][$form]);
        }
        $business[] = $this->line('Cost of goods sold', -$books['cogs'], 'COGS accounts', self::LINES['cogs'][$form]);
        $business[] = $this->line('Operating expenses recorded', -$books['expense'], 'Expense accounts per books', 'Various');
        if (! empty($projection['expenses'])) $business[] = $this->line('Projected expenses through Dec 31', -$projection['expenses'], $projection['expense_formula'], 'Various');
        foreach ($adj['lines'] as $l) $business[] = $l;
        $business[] = $this->line(['schedule_c' => 'Net profit (Schedule C line 31)', 'f1065' => 'Ordinary business income (Form 1065 line 23)', 'f1120s' => 'Ordinary business income (Form 1120-S line 21)', 'f1120' => 'Taxable income before NOL (Form 1120 line 28)'][$form], $net, 'Gross receipts − COGS − deductible expenses ± adjustments', '', true);

        // 2. Federal.
        $federal = [];
        $owner = null;
        $corporate = null;
        $share = $p['entity'] === 'partnership' || $p['entity'] === 's_corp' ? $p['ownership_pct'] / 100 : 1.0;
        if ($p['entity'] === 'c_corp') {
            $taxable = max(0, $net);
            $tax = $this->pct($taxable, $t['corporate_rate']);
            $corporate = ['taxable' => $taxable, 'tax' => $tax];
            $federal[] = $this->line('Corporate taxable income', $taxable, $net < 0 ? 'Net operating loss — carried forward (80% limit)' : 'Net business income', 'Form 1120 line 30');
            $federal[] = $this->line('Federal corporate income tax', $tax, 'Taxable income × '.($t['corporate_rate'] * 100).'% flat rate (IRC §11)', 'Form 1120 Sch. J line 2', true);
            // Owner's salary is taxed on the owner's own return.
            $owner = $this->personal($t, $p, ['business' => 0, 'se' => 0, 'qbi' => 0, 'qbi_wages' => 0, 'own_wages' => $p['officer_salary'] + $p['other_wages']]);
            $fedTotal = $tax;
        } else {
            $businessShare = (int) round($net * $share);
            $in = match ($p['entity']) {
                's_corp' => ['business' => $businessShare, 'se' => 0, 'qbi' => $businessShare, 'qbi_wages' => (int) round(($p['officer_salary'] + $p['w2_wages_paid']) * $share), 'own_wages' => $p['officer_salary'] + $p['other_wages']],
                'partnership' => ['business' => $businessShare, 'se' => $p['partner_se'] ? $businessShare : 0, 'qbi' => $businessShare, 'qbi_wages' => (int) round($p['w2_wages_paid'] * $share), 'own_wages' => $p['other_wages']],
                default => ['business' => $net, 'se' => $net, 'qbi' => $net, 'qbi_wages' => $p['w2_wages_paid'], 'own_wages' => $p['other_wages']],
            };
            $owner = $this->personal($t, $p, $in);
            $federal = $owner['lines'];
            $fedTotal = $owner['total'];
        }

        // Tax attributable to the business: owner's tax with vs without the business.
        $without = $this->personal($t, $p, ['business' => 0, 'se' => 0, 'qbi' => 0, 'qbi_wages' => 0, 'own_wages' => $p['other_wages']]);

        // 3. State.
        $state = $this->state($st, $p, $t, $net, $share, $grossReceipts, $owner, $form);
        foreach ($state['warnings'] as $w) $warnings[] = $w;

        // 4. Payroll (S/C corp) is remitted through payroll, not the income tax return.
        $payrollTotal = $payroll && $p['officer_salary'] > 0 ? $payroll['employer'] + $payroll['employee'] : 0;

        // 5. Payments, balance and quarterly estimates.
        $fedLiability = $fedTotal;
        $stateLiability = $state['total'];
        $fedEst = $this->estimates($year, $fedLiability, $p['fed_withholding'], $p['fed_estimates_paid'], $p['prior_year_fed_tax'], $p['prior_year_agi'], $t['estimated'], $fs, $p['entity'] === 'c_corp', $today);
        $stEst = $st && isset($st['data']['estimated']) ? $this->estimates($year, $stateLiability, $p['state_withholding'], $p['state_estimates_paid'], $p['prior_year_state_tax'], 0, $st['data']['estimated'], $fs, $p['entity'] === 'c_corp', $today) : null;

        $totalTax = $fedLiability + $stateLiability + $payrollTotal;
        // Balance due covers only the extra tax this business creates; W-2 withholding and other income are left out.
        $fedBusiness = $p['entity'] === 'c_corp' ? $fedLiability : $fedTotal - $without['total'];
        $stateBusiness = $p['entity'] === 'c_corp' ? $stateLiability : $stateLiability - ($state['without'] ?? 0);
        $summary = [
            'federal' => $fedLiability, 'state' => $stateLiability, 'payroll' => $payrollTotal,
            'owner_personal' => $p['entity'] === 'c_corp' ? $owner['total'] : null,
            'total' => $totalTax, 'paid' => $p['fed_estimates_paid'] + $p['state_estimates_paid'],
            'business_federal' => $fedBusiness, 'business_state' => $stateBusiness,
            'business_tax' => $fedBusiness + $stateBusiness,
            'balance' => $fedBusiness - $p['fed_estimates_paid'] + $stateBusiness - $p['state_estimates_paid'],
            'fed_balance' => $fedBusiness - $p['fed_estimates_paid'], 'state_balance' => $stateBusiness - $p['state_estimates_paid'],
            'net_business_income' => $net,
            'attributable' => $fedBusiness + $stateBusiness + $payrollTotal,
        ];
        $summary['effective_rate'] = $net > 0 ? round($summary['attributable'] / $net, 4) : null;
        $summary['set_aside_rate'] = $summary['effective_rate'];

        if (! $p['configured']) $warnings[] = 'Tax settings have not been reviewed yet — entity type was inferred from the W-9 settings. Confirm the settings below.';
        if ($books['uncategorized']) $warnings[] = 'Some expenses are in "Other" categories; review the deduction mapping before filing.';
        if ($p['entity'] === 's_corp' && $p['officer_salary'] <= 0 && $net > 0) $warnings[] = 'S corporation with no officer salary: the IRS expects reasonable compensation before distributions.';

        return [
            'year' => $year, 'from' => $from, 'to' => $yearEnd, 'as_of' => $asOf, 'projecting' => $projecting,
            'profile' => $p, 'entity' => TaxProfile::ENTITIES[$p['entity']], 'form' => $form,
            'tables' => ['US' => $us, 'state' => $st],
            'books' => $books, 'projection' => $projection, 'business' => $business, 'net' => $net,
            'return_lines' => $this->returnLines($books, $adj, $projection, $form, $homeOffice),
            'federal' => $federal, 'federal_total' => $fedTotal, 'owner' => $owner, 'without_business' => $without, 'corporate' => $corporate,
            'payroll' => $payroll, 'state' => $state,
            'estimates' => ['federal' => $fedEst, 'state' => $stEst],
            'filing' => $this->filingDates($year, $form, $p['entity']),
            'summary' => $summary, 'warnings' => $warnings,
            'assumptions' => $this->assumptions($p, $form),
        ];
    }

    // ---------------------------------------------------------------- books

    private function books(Business $b, string $from, string $to, string $method, string $form, array $t): array
    {
        $amounts = $this->reports->pnlAmounts($b, $from, $to, $method);
        $accounts = ChartAccount::where('business_id', $b->id)->get()->keyBy('id');
        $out = ['income' => 0, 'cogs' => 0, 'expense' => 0, 'rows' => [], 'meals' => 0, 'bad_debt' => 0, 'nondeductible' => 0, 'uncategorized' => false];
        foreach ($amounts as $id => $amt) {
            $a = $accounts[$id] ?? null;
            if (! $a || ! $amt) continue;
            if ($a->type === 'income') {
                $cat = $a->subtype === 'contra' ? 'returns' : 'gross';
                $out['income'] += $amt;
            } elseif ($a->subtype === 'cogs') {
                $cat = 'cogs';
                $out['cogs'] += $amt;
            } else {
                $cat = $this->category($a);
                $out['expense'] += $amt;
                if ($cat === 'meals') $out['meals'] += $amt;
                if ($cat === 'bad_debt') $out['bad_debt'] += $amt;
                if ($cat === 'nondeductible') $out['nondeductible'] += $amt;
                if ($cat === 'other' && preg_match('/other/i', $a->name)) $out['uncategorized'] = true;
            }
            $out['rows'][] = ['account' => $a, 'amount' => $amt, 'category' => $cat];
        }
        usort($out['rows'], fn ($x, $y) => strcmp($x['account']->code, $y['account']->code));

        return $out;
    }

    private function category(ChartAccount $a): string
    {
        if ($a->system_key === 'bad_debt') return 'bad_debt';
        if ($a->system_key === 'merchant_fees') return 'other';
        if (preg_match('/federal income tax|income tax expense|penalt|fines?\b|political/i', $a->name)) return 'nondeductible';
        foreach (self::CATEGORY_PATTERNS as $cat => $re) {
            if (preg_match($re, $a->name)) return $cat;
        }

        return 'other';
    }

    /** Book-to-tax differences (Schedule M-1 style). Positive = increases taxable income. */
    private function adjustments(Business $b, int $year, array $p, array $books, array $t): array
    {
        $lines = [];
        $form = match ($p['entity']) { 'partnership' => 'f1065', 's_corp' => 'f1120s', 'c_corp' => 'f1120', default => 'schedule_c' };
        if ($books['meals'] > 0) {
            $nd = $books['meals'] - $this->pct($books['meals'], $t['meals_deductible']);
            $lines[] = $this->line('Meals — nondeductible portion', $nd, (100 - $t['meals_deductible'] * 100).'% of '.$this->m($books['meals']).' business meals (IRC §274(n))', self::LINES['meals'][$form]);
        }
        if ($books['bad_debt'] > 0 && $p['tax_method'] === 'cash') {
            $lines[] = $this->line('Bad debt write-offs — not deductible on the cash method', $books['bad_debt'], 'Uncollected fees were never included in cash-basis income', self::LINES['bad_debt'][$form]);
        }
        if ($books['nondeductible'] > 0) {
            $lines[] = $this->line('Nondeductible expenses (income tax, penalties, political)', $books['nondeductible'], 'IRC §162(f), §275', '');
        }
        if ($p['section_179']) {
            $fixed = ChartAccount::where('business_id', $b->id)->where('subtype', 'fixed_asset')->pluck('id');
            $added = $fixed->isEmpty() ? 0 : (int) JournalLine::where('business_id', $b->id)->whereIn('account_id', $fixed)
                ->whereBetween('entry_date', ["{$year}-01-01", "{$year}-12-31"])->sum('debit_cents');
            if ($added > 0) {
                $limit = (int) $t['section_179']['limit'] * 100;
                $lines[] = $this->line('Equipment placed in service — §179 / 100% bonus depreciation', -min($added, $limit), 'Additions to fixed-asset accounts this year, expensed (Form 4562; §179 limit '.$this->m($limit).')', self::LINES['depreciation'][$form]);
            }
        }

        return ['lines' => $lines, 'total' => array_sum(array_column($lines, 'amount'))];
    }

    /** Receipts (and optionally expenses) still expected in the tax year. */
    private function projection(Business $b, Carbon $asOf, Carbon $yearEnd, array $p, array $books): array
    {
        $cash = $p['tax_method'] === 'cash';
        $open = [];
        $doubtful = [];
        $openTotal = 0;
        if ($cash && $p['include_open_invoices']) {
            foreach (Invoice::with('client')->where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->orderBy('due_date')->get() as $inv) {
                $preTax = $inv->total_cents > 0 ? (int) round($inv->balance_cents * ($inv->total_cents - $inv->tax_cents) / $inv->total_cents) : 0;
                $daysLate = $inv->due_date && $inv->due_date->lt($asOf) ? (int) $inv->due_date->diffInDays($asOf) : 0;
                $row = ['invoice' => $inv, 'open' => $inv->balance_cents, 'pre_tax' => $preTax, 'days_late' => $daysLate];
                if ($daysLate > $p['doubtful_after_days']) { $doubtful[] = $row + ['reason' => $daysLate.' days past due']; continue; }
                if ($inv->due_date && $inv->due_date->gt($yearEnd)) { $doubtful[] = $row + ['reason' => 'Due after Dec 31']; continue; }
                $open[] = $row;
                $openTotal += $preTax;
            }
        }
        $recurring = [];
        $recTotal = 0;
        if ($p['include_recurring']) {
            foreach (RecurringSchedule::with('lines', 'client')->where('business_id', $b->id)->where('status', 'active')->get() as $s) {
                $amount = $this->calc->scheduleSubtotalCents($s);
                foreach ($this->calc->upcoming($s, 60) as $date) {
                    if ($date->lte($asOf)) continue;
                    if ($date->gt($yearEnd)) break;
                    $expected = $cash ? $date->copy()->addDays((int) $s->terms_days) : $date;
                    if ($expected->gt($yearEnd)) continue;
                    $recurring[] = ['schedule' => $s, 'date' => $date, 'expected' => $expected, 'amount' => $amount];
                    $recTotal += $amount;
                }
            }
        }
        $expenses = 0;
        $formula = null;
        if ($p['project_expenses']) {
            $elapsed = max(1, (int) Carbon::create($asOf->year, 1, 1)->diffInDays($asOf) + 1);
            $remaining = (int) $asOf->diffInDays($yearEnd);
            $expenses = (int) round(($books['expense'] + $books['cogs']) / $elapsed * $remaining);
            $formula = $this->m($books['expense'] + $books['cogs']).' ÷ '.$elapsed.' days × '.$remaining.' remaining days';
        }

        return ['open' => $open, 'doubtful' => $doubtful, 'open_total' => $openTotal, 'recurring' => $recurring, 'recurring_total' => $recTotal,
            'income' => $openTotal + $recTotal, 'expenses' => $expenses, 'expense_formula' => $formula, 'cash' => $cash];
    }

    // ---------------------------------------------------------------- federal individual

    /**
     * Owner's Form 1040 for the business year.
     * @param array{business:int, se:int, qbi:int, qbi_wages:int, own_wages:int} $in
     */
    private function personal(array $t, array $p, array $in): array
    {
        $fs = $p['filing_status'];
        $lines = [];
        $wages = $in['own_wages'] + ($fs === 'mfj' ? $p['spouse_wages'] : 0);

        // Schedule SE
        $se = ['net_earnings' => 0, 'ss' => 0, 'medicare' => 0, 'total' => 0, 'half' => 0];
        if ($in['se'] > 0) {
            $ne = $this->pct($in['se'], $t['se']['net_earnings_factor']);
            if ($ne >= $t['se']['min_net_earnings'] * 100) {
                $room = max(0, $t['ss_wage_base'] * 100 - $in['own_wages']);
                $se['net_earnings'] = $ne;
                $se['ss'] = $this->pct(min($ne, $room), $t['se']['ss_rate']);
                $se['medicare'] = $this->pct($ne, $t['se']['medicare_rate']);
                $se['total'] = $se['ss'] + $se['medicare'];
                $se['half'] = $this->pct($se['total'], $t['se']['deductible_share']);
                $lines[] = $this->line('Net earnings from self-employment', $ne, $this->m($in['se']).' × '.($t['se']['net_earnings_factor'] * 100).'%', 'Schedule SE line 4a');
                $lines[] = $this->line('Social Security portion', $se['ss'], '12.4% × min(net earnings, '.$this->m($t['ss_wage_base'] * 100).' wage base − '.$this->m($in['own_wages']).' W-2 wages)', 'Schedule SE line 10');
                $lines[] = $this->line('Medicare portion', $se['medicare'], '2.9% × net earnings (no cap)', 'Schedule SE line 11');
                $lines[] = $this->line('Self-employment tax', $se['total'], 'Social Security + Medicare', 'Schedule SE line 12 → Sch. 2 line 4', true);
            }
        }

        // Adjustments to income
        $sehiCap = $p['entity'] === 's_corp' ? $p['officer_salary'] : max(0, $in['business'] - $se['half']);
        $sehi = min($p['health_insurance'], $sehiCap);
        $retirement = $p['retirement'];
        $agi = $in['business'] + $wages + $p['other_income'] - $se['half'] - $sehi - $retirement;
        if ($in['business']) $lines[] = $this->line($p['entity'] === 'sole_prop' || $p['entity'] === 'smllc' ? 'Business income (Schedule C)' : 'Business income (Schedule K-1 share)', $in['business'], $p['entity'] === 'partnership' || $p['entity'] === 's_corp' ? $p['ownership_pct'].'% ownership share' : 'Net profit', 'Schedule 1 line 3 / Schedule E line 32');
        if ($wages) $lines[] = $this->line('W-2 wages', $wages, $p['entity'] === 's_corp' ? 'Officer salary + other household wages' : 'Other household wages', 'Form 1040 line 1a');
        if ($p['other_income']) $lines[] = $this->line('Other income', $p['other_income'], 'Entered in tax settings', 'Form 1040');
        if ($se['half']) $lines[] = $this->line('Deductible part of self-employment tax', -$se['half'], '50% × SE tax', 'Schedule 1 line 15');
        if ($sehi) $lines[] = $this->line('Self-employed health insurance', -$sehi, 'Premiums, limited to business income', 'Schedule 1 line 17');
        if ($retirement) $lines[] = $this->line('SEP / SIMPLE / solo 401(k) contributions', -$retirement, 'Entered in tax settings', 'Schedule 1 line 16');
        $lines[] = $this->line('Adjusted gross income', $agi, 'Income − adjustments', 'Form 1040 line 11', true);

        $std = (int) $t['standard_deduction'][$fs] * 100;
        $deduction = max($std, $p['itemized']);
        $lines[] = $this->line($deduction === $std ? 'Standard deduction' : 'Itemized deductions', -$deduction, $deduction === $std ? TaxProfile::FILING_STATUSES[$fs].' standard deduction' : 'Entered itemized deductions (Schedule A)', 'Form 1040 line 12');
        $tiBefore = max(0, $agi - $deduction);

        // §199A qualified business income deduction
        $qbiBase = $in['qbi'] - ($in['se'] > 0 ? $se['half'] + $sehi + $retirement : 0);
        $qbi = $this->qbi($t['qbi'], $fs, $qbiBase, $in['qbi_wages'], $tiBefore, (bool) $p['sstb']);
        if ($qbi['deduction']) $lines[] = $this->line('Qualified business income deduction (§199A)', -$qbi['deduction'], $qbi['formula'], 'Form 1040 line 13 (Form 8995/8995-A)');
        $taxable = max(0, $tiBefore - $qbi['deduction']);
        $lines[] = $this->line('Taxable income', $taxable, 'AGI − deduction − QBI deduction', 'Form 1040 line 15', true);

        [$incomeTax, $bands] = $this->bracketTax($taxable, $t['brackets'][$fs]);
        $lines[] = $this->line('Income tax', $incomeTax, 'Tax rate schedule — '.TaxProfile::FILING_STATUSES[$fs].' (see bracket detail)', 'Form 1040 line 16', true);

        $ctc = 0;
        if ($p['dependents'] > 0 && isset($t['child_tax_credit'])) {
            $c = $t['child_tax_credit'];
            $full = $p['dependents'] * $c['amount'] * 100;
            $over = max(0, $agi - $c['phaseout'][$fs] * 100);
            $reduction = (int) ceil($over / 100000) * $c['reduction_per_1000'] * 100;
            $ctc = min($incomeTax, max(0, $full - $reduction));
            $lines[] = $this->line('Child tax credit', -$ctc, $p['dependents'].' × '.$this->m($c['amount'] * 100).' − $'.$c['reduction_per_1000'].' per $1,000 of AGI over '.$this->m($c['phaseout'][$fs] * 100).', limited to tax', 'Form 1040 line 19 (Schedule 8812)');
        }

        // Form 8959 Additional Medicare Tax
        $am = $t['additional_medicare'];
        $thr = $am['threshold'][$fs] * 100;
        $amWages = max(0, $wages - $thr);
        $amSe = max(0, $se['net_earnings'] - max(0, $thr - $wages));
        $addMed = $this->pct($amWages + $amSe, $am['rate']);
        if ($addMed) $lines[] = $this->line('Additional Medicare Tax', $addMed, '0.9% × (wages + SE earnings over '.$this->m($thr).')', 'Form 8959 → Sch. 2 line 11');
        if ($se['total']) $lines[] = $this->line('Self-employment tax', $se['total'], 'From Schedule SE above', 'Schedule 2 line 4');

        $total = $incomeTax - $ctc + $se['total'] + $addMed;
        $lines[] = $this->line('Total federal tax', $total, 'Income tax − credits + SE tax + Additional Medicare', 'Form 1040 line 24', true);
        $marginal = 0;
        foreach ($bands as $band) if ($band['taxed'] > 0) $marginal = $band['rate'];

        return ['lines' => $lines, 'total' => $total, 'agi' => $agi, 'taxable' => $taxable, 'income_tax' => $incomeTax, 'bands' => $bands,
            'se' => $se, 'qbi' => $qbi, 'deduction' => $deduction, 'ctc' => $ctc, 'additional_medicare' => $addMed, 'marginal_rate' => $marginal,
            'sehi' => $sehi, 'retirement' => $retirement, 'wages' => $wages];
    }

    /** §199A: 20% of QBI, SSTB phase-out and W-2 wage limit above the threshold, capped at 20% of taxable income. */
    private function qbi(array $q, string $fs, int $qbi, int $w2, int $tiBefore, bool $sstb): array
    {
        if ($qbi <= 0) return ['deduction' => 0, 'formula' => 'No positive qualified business income'];
        $thr = $q['threshold'][$fs] * 100;
        $range = $q['phase_in'][$fs] * 100;
        $tentative = $this->pct($qbi, $q['rate']);
        $wageLimit = $this->pct($w2, 0.5);
        if ($tiBefore <= $thr) {
            $ded = $tentative;
            $formula = '20% × '.$this->m($qbi).' QBI (taxable income under the '.$this->m($thr).' threshold)';
        } elseif ($tiBefore >= $thr + $range) {
            $ded = $sstb ? 0 : min($tentative, $wageLimit);
            $formula = $sstb ? 'Specified service business above '.$this->m($thr + $range).': no deduction' : 'Lesser of 20% QBI or 50% of W-2 wages ('.$this->m($wageLimit).')';
        } else {
            $phase = ($tiBefore - $thr) / $range;
            $applicable = $sstb ? 1 - $phase : 1;
            $t2 = $this->pct((int) round($qbi * $applicable), $q['rate']);
            $wl = $this->pct((int) round($w2 * $applicable), 0.5);
            $ded = $t2 > $wl ? $t2 - (int) round(($t2 - $wl) * $phase) : $t2;
            $formula = 'Phase-in: taxable income is '.round($phase * 100, 1).'% through the '.$this->m($range).' range'.($sstb ? '; SSTB applicable percentage '.round($applicable * 100, 1).'%' : '').'; W-2 wage limit reduction applied';
        }
        $cap = $this->pct($tiBefore, $q['rate']);
        if ($ded > $cap) {
            $ded = $cap;
            $formula .= '; limited to 20% of taxable income before QBI';
        }
        if (! empty($q['min_deduction']) && $qbi >= $q['min_qbi'] * 100 && $ded < $q['min_deduction'] * 100) {
            $ded = min($q['min_deduction'] * 100, $tiBefore);
            $formula .= '; minimum deduction '.$this->m($q['min_deduction'] * 100).' for at least '.$this->m($q['min_qbi'] * 100).' of active QBI';
        }

        return ['deduction' => max(0, $ded), 'formula' => $formula];
    }

    private function payroll(int $salary, array $t): array
    {
        $ssWages = min($salary, $t['ss_wage_base'] * 100);
        $ss = $this->pct($ssWages, $t['fica']['ss_rate']);
        $med = $this->pct($salary, $t['fica']['medicare_rate']);
        $futa = $this->pct(min($salary, $t['futa']['wage_base'] * 100), $t['futa']['rate']);

        return ['salary' => $salary, 'ss' => $ss, 'medicare' => $med, 'futa' => $futa, 'employer' => $ss + $med + $futa, 'employee' => $ss + $med,
            'lines' => [
                $this->line('Officer salary (W-2 box 1)', $salary, 'Reasonable compensation', 'Form 941 / W-2'),
                $this->line('Employer Social Security', $ss, '6.2% × min(salary, '.$this->m($t['ss_wage_base'] * 100).')', 'Form 941 line 5a'),
                $this->line('Employer Medicare', $med, '1.45% × salary', 'Form 941 line 5c'),
                $this->line('FUTA', $futa, '0.6% × first '.$this->m($t['futa']['wage_base'] * 100).' (after state credit)', 'Form 940'),
                $this->line('Employee Social Security + Medicare (withheld)', $ss + $med, '7.65% (Social Security capped at the wage base)', 'Form 941 lines 5a/5c'),
                $this->line('Total payroll taxes', $ss + $med + $futa + $ss + $med, 'Employer + employee shares', '', true),
            ]];
    }

    // ---------------------------------------------------------------- state

    private function state(?array $st, array $p, array $us, int $net, float $share, int $gross, array $owner, string $form): array
    {
        $out = ['name' => $p['state'], 'lines' => [], 'total' => 0, 'without' => 0, 'bands' => [], 'warnings' => [], 'entity_tax' => 0];
        if (! $st) return $out;
        $d = $st['data'];
        $out['name'] = $d['name'] ?? $p['state'];
        if (($d['type'] ?? '') === 'none') {
            $out['lines'][] = $this->line($p['state'].' has no personal income tax', 0, '', '');

            return $out;
        }
        $fs = $p['filing_status'];
        $mapped = $d['status_map'][$fs] ?? $fs;
        $entityTax = 0;

        if ($p['entity'] === 'c_corp') {
            $c = $d['corporate'] ?? null;
            if (! $c) {
                $out['warnings'][] = 'No corporate tax parameters for '.$p['state'].'.';

                return $out;
            }
            $taxable = max(0, $net);
            $rate = $this->tier($c['tiers'] ?? [[0, 0]], $taxable / 100);
            $tax = $this->pct($taxable, $rate);
            $min = (int) round($this->tier($c['minimum_tax'] ?? [[0, 0]], $gross / 100) * 100);
            $out['lines'][] = $this->line($c['name'].' — entire net income', $taxable, 'Federal taxable income before NOL (state modifications not modeled)', 'CBT-100');
            $out['lines'][] = $this->line('Tax at '.($rate * 100).'%', $tax, 'Rate set by total income tier', 'CBT-100');
            if (isset($c['surtax']) && $taxable > $c['surtax']['threshold'] * 100) {
                $sur = $this->pct($taxable, $c['surtax']['rate']);
                $out['lines'][] = $this->line($c['surtax']['label'], $sur, ($c['surtax']['rate'] * 100).'% of allocated net income over '.$this->m($c['surtax']['threshold'] * 100), 'CBT-100');
                $tax += $sur;
            }
            if ($min > $tax) {
                $out['lines'][] = $this->line('Minimum tax applies', $min - $tax, 'Minimum tax based on '.$this->m($gross).' gross receipts', 'CBT-100');
                $tax = $min;
            }
            $out['lines'][] = $this->line('State corporate tax', $tax, '', '', true);
            $out['total'] = $tax;

            return $out;
        }

        if ($p['entity'] === 's_corp' && isset($d['corporate']['s_corp_minimum_tax'])) {
            $entityTax = (int) round($this->tier($d['corporate']['s_corp_minimum_tax'], $gross / 100) * 100);
            $out['lines'][] = $this->line('S corporation minimum tax', $entityTax, 'Based on '.$this->m($gross).' gross receipts', 'CBT-100S');
        }
        if ($p['entity'] === 'partnership' && isset($d['partnership_fee']) && $p['owner_count'] >= $d['partnership_fee']['min_owners']) {
            $entityTax = $p['owner_count'] * $d['partnership_fee']['per_owner'] * 100;
            $out['lines'][] = $this->line('Partnership filing fee', $entityTax, $p['owner_count'].' owners × $'.$d['partnership_fee']['per_owner'], 'NJ-1065');
        }

        $business = $p['entity'] === 'c_corp' ? 0 : (int) round($net * ($p['entity'] === 'sole_prop' || $p['entity'] === 'smllc' ? 1 : $share));
        $calc = function (int $business, int $ownWages) use ($d, $p, $fs, $mapped, $owner) {
            $lines = [];
            $wages = $ownWages + ($fs === 'mfj' ? $p['spouse_wages'] : 0);
            $income = $business + $wages + $p['other_income'];
            $lines[] = $this->line('Business income', $business, 'Same as federal business income (state modifications not modeled)', 'State return');
            if ($wages) $lines[] = $this->line('Wages', $wages, '', '');
            if ($p['other_income']) $lines[] = $this->line('Other income', $p['other_income'], '', '');
            $deductions = 0;
            if ($business && ! empty($d['allows_se_tax_deduction'])) $deductions += $owner['se']['half'];
            if ($business && ($p['health_insurance'] ?? 0)) $deductions += $owner['sehi'];
            if (! empty($d['allows_retirement_deduction'])) $deductions += $p['retirement'];
            if ($deductions) $lines[] = $this->line('Deductions allowed by the state', -$deductions, (! empty($d['allows_se_tax_deduction']) ? 'Half SE tax, ' : '').'self-employed health insurance'.(! empty($d['allows_retirement_deduction']) ? ', retirement contributions' : ''), '');
            $std = (int) (($d['standard_deduction'][$fs] ?? 0) * 100);
            if ($std) $lines[] = $this->line('Standard deduction', -$std, '', '');
            $ex = 0;
            if (isset($d['personal_exemption'])) {
                $e = $d['personal_exemption'];
                $ex = ($e['taxpayer'] + ($fs === 'mfj' ? $e['spouse'] : 0) + $p['dependents'] * $e['dependent']) * 100;
                $lines[] = $this->line('Personal exemptions', -$ex, '$'.number_format($e['taxpayer']).' taxpayer'.($fs === 'mfj' ? ' + $'.number_format($e['spouse']).' spouse' : '').($p['dependents'] ? ' + '.$p['dependents'].' × $'.number_format($e['dependent']).' dependents' : ''), '');
            }
            $taxable = max(0, $income - $deductions - $std - $ex);
            $lines[] = $this->line('State taxable income', $taxable, '', '', true);
            if (($d['type'] ?? '') === 'flat') {
                $tax = $this->pct($taxable, (float) $d['flat_rate']);
                $bands = [];
                $lines[] = $this->line('Income tax', $tax, ($d['flat_rate'] * 100).'% flat rate', '', true);
            } else {
                [$tax, $bands] = $this->bracketTax($taxable, $d['brackets'][$mapped] ?? $d['brackets']['single']);
                $lines[] = $this->line('Income tax', $tax, 'Rate schedule — '.(TaxProfile::FILING_STATUSES[$mapped] ?? $mapped).' (see bracket detail)', '', true);
            }

            return [$lines, $tax, $bands];
        };
        $ownWages = ($p['entity'] === 's_corp' ? $p['officer_salary'] : 0) + $p['other_wages'];
        [$lines, $tax, $bands] = $calc($business, $ownWages);
        [, $without] = $calc(0, $p['other_wages']);
        $out['lines'] = array_merge($out['lines'], $lines);
        $out['bands'] = $bands;
        $out['total'] = $tax + $entityTax;
        $out['entity_tax'] = $entityTax;
        $out['without'] = $without;
        if ($entityTax) $out['lines'][] = $this->line('Total state tax', $out['total'], 'Personal income tax + entity-level tax/fees', '', true);

        return $out;
    }

    // ---------------------------------------------------------------- estimates

    private function estimates(int $year, int $liability, int $withholding, int $paid, int $priorTax, int $priorAgi, array $rules, string $fs, bool $corporate, Carbon $today): array
    {
        $current = $this->pct($liability, $corporate ? 1.0 : $rules['current_year_pct']);
        $priorPct = $priorAgi > ($rules['high_agi'][$fs] ?? PHP_INT_MAX) * 100 ? ($rules['high_agi_prior_year_pct'] ?? 1.0) : $rules['prior_year_pct'];
        $prior = $priorTax > 0 ? $this->pct($priorTax, $priorPct) : null;
        $required = $prior !== null ? min($current, $prior) : $current;
        $formula = $prior !== null
            ? 'Lesser of '.($corporate ? '100' : $rules['current_year_pct'] * 100).'% of this year ('.$this->m($current).') or '.($priorPct * 100).'% of last year ('.$this->m($prior).')'
            : ($corporate ? '100' : $rules['current_year_pct'] * 100).'% of this year’s tax (enter last year’s tax for the prior-year safe harbor)';
        $dueRule = $corporate && isset($rules['corporate_due']) ? $rules['corporate_due'] : $rules['due'];
        $needEstimates = $liability - $withholding >= ($corporate ? 500 : ($rules['threshold'] ?? 1000)) * 100;
        $installments = [];
        $remainingDue = max(0, $required - $withholding - $paid);
        $upcoming = 0;
        foreach ($dueRule as $i => $d) {
            $date = Carbon::parse(($d[0] === '+' ? $year + 1 : $year).'-'.ltrim($d, '+'));
            while ($date->isWeekend()) $date->addDay();
            $installments[] = ['n' => $i + 1, 'due' => $date, 'cumulative' => (int) round($required * ($i + 1) / count($dueRule)), 'past' => $date->lt($today)];
            if (! $date->lt($today)) $upcoming++;
        }
        foreach ($installments as &$ins) {
            $ins['suggested'] = ! $ins['past'] && $upcoming ? (int) ceil($remainingDue / $upcoming) : 0;
        }
        unset($ins);

        return ['liability' => $liability, 'required' => $required, 'formula' => $formula, 'withholding' => $withholding, 'paid' => $paid,
            'remaining' => $remainingDue, 'needed' => $needEstimates, 'installments' => $installments,
            'balance_at_filing' => $liability - $withholding - $paid - ($upcoming ? $remainingDue : 0)];
    }

    /** Return deadlines for a calendar-year filer (weekend roll-forward only; holidays not modeled). */
    private function filingDates(int $year, string $form, string $entity): array
    {
        $due = function (int $m, int $d) use ($year) {
            $date = Carbon::create($year + 1, $m, $d);
            while ($date->isWeekend()) $date->addDay();

            return $date;
        };
        $out = [];
        if (in_array($form, ['f1065', 'f1120s'], true)) $out[] = ['label' => ($form === 'f1065' ? 'Form 1065' : 'Form 1120-S').' and Schedules K-1', 'due' => $due(3, 15), 'extended' => $due(9, 15)];
        if ($form === 'f1120') $out[] = ['label' => 'Form 1120', 'due' => $due(4, 15), 'extended' => $due(10, 15)];
        if ($entity !== 'c_corp') $out[] = ['label' => 'Owner’s Form 1040'.($form === 'schedule_c' ? ' with Schedules C and SE' : ''), 'due' => $due(4, 15), 'extended' => $due(10, 15)];

        return $out;
    }

    // ---------------------------------------------------------------- return lines

    private function returnLines(array $books, array $adj, ?array $projection, string $form, int $homeOffice): array
    {
        $lines = [];
        $add = function (string $cat, int $amount) use (&$lines, $form) {
            $label = self::LINES[$cat][$form] ?? self::LINES['other'][$form];
            $lines[$label] = ($lines[$label] ?? 0) + $amount;
        };
        foreach ($books['rows'] as $r) {
            $cat = $r['category'] === 'nondeductible' ? null : $r['category'];
            if (! $cat) continue;
            $amount = $cat === 'returns' ? -$r['amount'] : $r['amount'];
            $add($cat, $amount);
        }
        if ($projection && $projection['income']) $add('gross', $projection['income']);
        foreach ($adj['lines'] as $l) {
            if (str_starts_with($l['label'], 'Meals')) $add('meals', -$l['amount']);
            if (str_starts_with($l['label'], 'Bad debt')) $add('bad_debt', -$l['amount']);
            if (str_starts_with($l['label'], 'Equipment')) $add('depreciation', -$l['amount']);
            if (str_starts_with($l['label'], 'Officer')) $add('officer_comp', -$l['amount']);
            if (str_starts_with($l['label'], 'Employer payroll')) $add('payroll_taxes', -$l['amount']);
        }
        if ($homeOffice) $add('home_office', $homeOffice);
        uksort($lines, fn ($a, $b) => (float) $a <=> (float) $b ?: strcmp($a, $b));

        return array_map(fn ($k, $v) => ['line' => $k, 'amount' => $v], array_keys($lines), $lines);
    }

    private function assumptions(array $p, string $form): array
    {
        return array_values(array_filter([
            'Tax year is the calendar year; '.ucfirst($p['tax_method']).' method.',
            $p['tax_method'] === 'cash' ? 'Cash method: income is counted when collected and expenses when paid (cash projection of the ledger).' : 'Accrual method: income when invoiced, expenses when incurred.',
            $p['include_open_invoices'] && $p['tax_method'] === 'cash' ? 'Open invoices due by Dec 31 and no more than '.$p['doubtful_after_days'].' days past due are assumed collected this year (sales tax excluded).' : null,
            $p['include_recurring'] ? 'Scheduled recurring invoices are assumed issued'.($p['tax_method'] === 'cash' ? ' and paid within terms' : '').' before year end.' : null,
            $form === 'schedule_c' || $p['entity'] === 'partnership' ? 'The business is assumed to be an active trade or business with material participation.' : null,
            $p['sstb'] ? 'Treated as a specified service trade or business (consulting) for the QBI deduction.' : null,
            'Not modeled: capital gains and qualified dividends rates, AMT, the 3.8% net investment income tax, credits other than the child tax credit, local/city taxes, state modifications and credits, NOL carryovers, prior-year overpayments applied.',
            'Balance due is the extra tax this business’s earnings add (return with vs without the business) minus estimated payments entered; W-2 withholding and tax on other income are not included. Payments are not read from the ledger.',
        ]));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0:int, 1:list<array{from:int, to:?int, rate:float, taxed:int, tax:int}>} */
    public function bracketTax(int $taxableCents, array $brackets): array
    {
        $tax = 0.0;
        $bands = [];
        foreach ($brackets as $i => [$floor, $rate]) {
            $lo = (int) round($floor * 100);
            $hi = isset($brackets[$i + 1]) ? (int) round($brackets[$i + 1][0] * 100) : null;
            $taxed = max(0, min($taxableCents, $hi ?? PHP_INT_MAX) - $lo);
            $bandTax = $taxed * $rate;
            $tax += $bandTax;
            $bands[] = ['from' => $lo, 'to' => $hi, 'rate' => (float) $rate, 'taxed' => $taxed, 'tax' => (int) round($bandTax)];
        }

        return [(int) round($tax), $bands];
    }

    private function tier(array $tiers, float $amountDollars): float
    {
        $v = 0;
        foreach ($tiers as [$floor, $value]) if ($amountDollars >= $floor) $v = $value;

        return (float) $v;
    }

    private function pct(int $cents, float $rate): int
    {
        return (int) round($cents * $rate);
    }

    private function m(int $cents): string
    {
        return \App\Support\Money::format($cents);
    }

    private function line(string $label, int $amount, string $formula, string $ref, bool $strong = false): array
    {
        return compact('label', 'amount', 'formula', 'ref', 'strong');
    }
}
