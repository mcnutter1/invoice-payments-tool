<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Services\Audit;
use App\Services\Documents\PdfRenderer;
use App\Services\Reports\ReportService;
use App\Support\Money;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index()
    {
        $ctx = $this->ctx();
        $available = array_filter(ReportService::REPORTS, fn ($r) => $ctx->can($r[1]) || ($r[1] === 'reports.ar.view' && $ctx->can('reports.pnl.view')));

        return view('reports.index', ['reports' => $available]);
    }

    public function show(Request $request, string $report, PdfRenderer $pdf)
    {
        abort_unless(isset(ReportService::REPORTS[$report]), 404);
        [$title, $perm] = ReportService::REPORTS[$report];
        $ctx = $this->ctx();
        if (! $ctx->can($perm) && ! ($perm === 'reports.ar.view' && $ctx->can('reports.pnl.view'))) abort(403);
        $b = $this->biz();
        [$from, $to, $preset] = $this->dateRange($request);
        $asOf = $request->query('as_of', $b->localToday()->toDateString());
        $basis = in_array($request->query('basis'), ['cash', 'accrual'], true) ? $request->query('basis') : ($b->reporting_basis ?: 'accrual');
        $accountId = $request->query('account') ? (int) $request->query('account') : null;
        if ($accountId) ChartAccount::where('business_id', $b->id)->findOrFail($accountId);

        $data = match ($report) {
            'profit-and-loss' => $this->reports->profitAndLoss($b, $from, $to, $basis, $request->boolean('monthly')),
            'balance-sheet' => $this->reports->balanceSheet($b, $asOf),
            'trial-balance' => $this->reports->trialBalance($b, $asOf),
            'general-ledger' => ['accounts' => $this->reports->generalLedger($b, $accountId, $from, $to, $basis)],
            'ar-aging' => $this->reports->arAging($b, $asOf),
            'ap-aging' => $this->reports->apAging($b, $asOf),
            'invoice-summary' => $this->reports->invoiceSummary($b, $from, $to),
            'sales-by-client' => $this->reports->salesByClient($b, $from, $to),
            'sales-by-item' => $this->reports->salesByItem($b, $from, $to),
            'expenses-by-category' => $this->reports->expensesBy($b, $from, $to, 'category', $basis),
            'expenses-by-vendor' => $this->reports->expensesBy($b, $from, $to, 'vendor'),
            'sales-tax' => $this->reports->salesTax($b, $from, $to),
            'recurring-forecast' => $this->reports->recurringForecast($b, (int) $request->query('months', 6)),
            'contract-values' => $this->reports->contractValues($b),
        };
        $meta = [
            'report' => $report, 'title' => $title, 'from' => $from, 'to' => $to, 'preset' => $preset, 'asOf' => $asOf,
            'basis' => $basis, 'accountId' => $accountId, 'generatedAt' => now($b->timezone),
            'accounts' => ChartAccount::where('business_id', $b->id)->orderBy('code')->get(),
        ];

        $format = $request->query('format');
        if (in_array($format, ['csv', 'pdf'], true)) {
            $this->require('reports.export');
            Audit::log($b, 'report.exported', null, null, ['report' => $report, 'format' => $format, 'from' => $from, 'to' => $to, 'as_of' => $asOf, 'basis' => $basis]);
            if ($format === 'csv') {
                return $this->csv($report, $data, $meta);
            }

            return response($pdf->render('reports.print', ['data' => $data, 'meta' => $meta, 'business' => $b]), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$report.'-'.now()->format('Ymd').'.pdf"',
            ]);
        }

        return view('reports.show', ['data' => $data, 'meta' => $meta]);
    }

    private function csv(string $report, array $data, array $meta)
    {
        $rows = app(\App\Services\Reports\ReportCsv::class)->rows($report, $data, $meta);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Business', $this->biz()->name()], ',', '"', '\\');
        fputcsv($out, ['Report', $meta['title'], 'Basis', $meta['basis'], 'Currency', 'USD', 'Generated', $meta['generatedAt']->toDateTimeString()], ',', '"', '\\');
        foreach ($rows as $r) {
            // Neutralize spreadsheet formula injection.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $r), ',', '"', '\\');
        }
        rewind($out);

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$report.'-'.now()->format('Ymd').'.csv"',
        ]);
    }
}
