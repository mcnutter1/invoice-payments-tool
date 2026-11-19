<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Documents\PdfRenderer;
use App\Services\Tax\TaxEstimator;
use App\Services\Tax\TaxProfile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxReportController extends Controller
{
    private function years(): array
    {
        $y = $this->biz()->localToday()->year;

        return range($y, $y - 3);
    }

    public function show(Request $request, TaxEstimator $estimator, PdfRenderer $pdf)
    {
        $b = $this->biz();
        $year = in_array((int) $request->query('year'), $this->years(), true) ? (int) $request->query('year') : $this->years()[0];
        $est = $estimator->estimate($b, $year);

        $format = $request->query('format');
        if (in_array($format, ['csv', 'pdf'], true)) {
            $this->require('reports.export');
            Audit::log($b, 'report.exported', null, null, ['report' => 'tax-estimate', 'format' => $format, 'year' => $year]);
            $name = 'tax-estimate-'.$year.'-'.now()->format('Ymd');
            if ($format === 'pdf') {
                return response($pdf->render('reports.tax.print', ['e' => $est, 'business' => $b, 'generatedAt' => now($b->timezone)]), 200, [
                    'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                ]);
            }

            return $this->csv($est, $name);
        }

        return view('reports.tax.show', ['e' => $est, 'years' => $this->years(), 'canEdit' => $this->ctx()->can('business.manage'), 'generatedAt' => now($b->timezone)]);
    }

    public function saveProfile(Request $request)
    {
        $b = $this->biz();
        $money = ['nullable', 'numeric', 'min:0', 'max:100000000'];
        $data = $request->validate([
            'year' => ['required', Rule::in($this->years())],
            'entity' => ['required', Rule::in(array_keys(TaxProfile::ENTITIES))],
            'filing_status' => ['required', Rule::in(array_keys(TaxProfile::FILING_STATUSES))],
            'state' => ['required', 'regex:/^[A-Za-z]{2}$/'],
            'tax_method' => ['required', Rule::in(['cash', 'accrual'])],
            'ownership_pct' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'owner_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'dependents' => ['required', 'integer', 'min:0', 'max:20'],
            'home_office_sqft' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'doubtful_after_days' => ['required', 'integer', 'min:0', 'max:730'],
            'sstb' => ['nullable', 'boolean'], 'partner_se' => ['nullable', 'boolean'], 'section_179' => ['nullable', 'boolean'],
            'include_open_invoices' => ['nullable', 'boolean'], 'include_recurring' => ['nullable', 'boolean'], 'project_expenses' => ['nullable', 'boolean'],
            'salary_in_books' => ['nullable', 'boolean'],
        ] + array_fill_keys(array_diff(TaxProfile::yearlyKeys(), ['salary_in_books']), $money));
        $general = [
            'entity' => $data['entity'], 'filing_status' => $data['filing_status'], 'state' => strtoupper($data['state']), 'tax_method' => $data['tax_method'],
            'ownership_pct' => (float) $data['ownership_pct'], 'owner_count' => (int) $data['owner_count'], 'dependents' => (int) $data['dependents'],
            'home_office_sqft' => (int) ($data['home_office_sqft'] ?? 0), 'doubtful_after_days' => (int) $data['doubtful_after_days'],
        ];
        foreach (['sstb', 'partner_se', 'section_179', 'include_open_invoices', 'include_recurring', 'project_expenses'] as $k) $general[$k] = $request->boolean($k);
        $yearly = ['salary_in_books' => $request->boolean('salary_in_books')];
        foreach (array_diff(TaxProfile::yearlyKeys(), ['salary_in_books']) as $k) {
            $yearly[$k] = ($data[$k] ?? '') === '' || $data[$k] === null ? 0 : $this->cents($data[$k]);
        }
        TaxProfile::save($b, (int) $data['year'], $general, $yearly);
        Audit::log($b, 'business.tax_profile_updated', $b, null, ['year' => (int) $data['year'], 'entity' => $general['entity'], 'state' => $general['state']]);

        return $this->to('/reports/tax-estimate?year='.$data['year'], 'Tax settings saved.');
    }

    private function csv(array $e, string $name)
    {
        $m = fn (int $c) => Money::toDecimalString($c);
        $rows = [['Tax estimate', $e['year']], ['Entity', $e['entity'][0], $e['entity'][1]], ['Method', $e['profile']['tax_method']], ['As of', $e['as_of']->toDateString()], []];
        $section = function (string $title, array $lines) use (&$rows, $m) {
            $rows[] = [$title];
            $rows[] = ['Line', 'Amount', 'Formula', 'Return reference'];
            foreach ($lines as $l) $rows[] = [$l['label'], $m($l['amount']), $l['formula'], $l['ref']];
            $rows[] = [];
        };
        $section('Business income', $e['business']);
        $rows[] = ['Return lines'];
        foreach ($e['return_lines'] as $r) $rows[] = [$r['line'], $m($r['amount'])];
        $rows[] = [];
        $section('Federal', $e['federal']);
        if ($e['payroll']) $section('Payroll taxes', $e['payroll']['lines']);
        $section('State — '.$e['state']['name'], $e['state']['lines']);
        $rows[] = ['Summary'];
        foreach (['federal' => 'Federal (whole return)', 'state' => 'State (whole return)', 'payroll' => 'Payroll', 'total' => 'Total (whole return)', 'business_tax' => 'Tax from this business', 'paid' => 'Estimates paid', 'balance' => 'Balance due (this business)'] as $k => $l) $rows[] = [$l, $m((int) $e['summary'][$k])];
        $rows[] = [];
        $rows[] = ['Owed or refunded (whole return)', 'Federal', 'State'];
        foreach (['other' => 'Tax on W-2 wages & other income', 'business' => 'Tax from this business', 'total' => 'Total tax', 'withholding' => 'Withholding', 'estimates' => 'Estimated payments', 'balance' => 'Owed (negative = refund)'] as $k => $l) {
            $rows[] = [$l, $m((int) $e['settlement']['federal'][$k]), $m((int) $e['settlement']['state'][$k])];
        }
        foreach ($e['warnings'] as $w) $rows[] = ['Warning', $w];
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $r), ',', '"', '\\');
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"']);
    }
}
