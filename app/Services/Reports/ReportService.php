<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Models\CashProjectionEvent;
use App\Models\ChartAccount;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\PaymentAllocation;
use App\Models\RecurringSchedule;
use App\Models\VendorPayment;
use App\Services\Recurring\RecurrenceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    public const REPORTS = [
        'profit-and-loss' => ['Profit & loss', 'reports.pnl.view'],
        'balance-sheet' => ['Balance sheet', 'reports.pnl.view'],
        'trial-balance' => ['Trial balance', 'reports.pnl.view'],
        'general-ledger' => ['General ledger', 'reports.pnl.view'],
        'ar-aging' => ['A/R aging', 'reports.ar.view'],
        'ap-aging' => ['A/P aging', 'reports.pnl.view'],
        'invoice-summary' => ['Invoices & collections', 'reports.ar.view'],
        'sales-by-client' => ['Sales by client', 'reports.pnl.view'],
        'sales-by-item' => ['Sales by product/service', 'reports.pnl.view'],
        'expenses-by-category' => ['Expenses by category', 'reports.pnl.view'],
        'expenses-by-vendor' => ['Expenses by vendor', 'reports.pnl.view'],
        'sales-tax' => ['Sales tax liability', 'reports.pnl.view'],
        'recurring-forecast' => ['Recurring billing forecast', 'reports.ar.view'],
        'contract-values' => ['Contract values', 'reports.ar.view'],
    ];

    public function accounts(Business $b): Collection
    {
        return ChartAccount::where('business_id', $b->id)->orderBy('code')->get()->keyBy('id');
    }

    /** Signed (debit - credit) balances per account. */
    public function balances(Business $b, ?string $from, string $to): array
    {
        $q = JournalLine::where('business_id', $b->id)->where('entry_date', '<=', $to);
        if ($from) $q->where('entry_date', '>=', $from);

        return $q->selectRaw('account_id, SUM(debit_cents) - SUM(credit_cents) as bal')->groupBy('account_id')->pluck('bal', 'account_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** P&L natural amounts per account (income positive as credit, expense positive as debit). */
    public function pnlAmounts(Business $b, string $from, string $to, string $basis): array
    {
        $accounts = $this->accounts($b);
        if ($basis === 'cash') {
            $raw = CashProjectionEvent::where('business_id', $b->id)->whereBetween('event_date', [$from, $to])
                ->selectRaw('account_id, SUM(amount_cents) as amt')->groupBy('account_id')->pluck('amt', 'account_id');
            $out = [];
            foreach ($raw as $id => $amt) {
                $a = $accounts[$id] ?? null;
                if ($a && in_array($a->type, ['income', 'expense'], true)) $out[$id] = (int) $amt;
            }

            return $out;
        }
        $bal = $this->balances($b, $from, $to);
        $out = [];
        foreach ($bal as $id => $v) {
            $a = $accounts[$id] ?? null;
            if (! $a) continue;
            if ($a->type === 'income') $out[$id] = -$v;
            elseif ($a->type === 'expense') $out[$id] = $v;
        }

        return $out;
    }

    public function profitAndLoss(Business $b, string $from, string $to, string $basis, bool $monthly = false): array
    {
        $accounts = $this->accounts($b);
        $periods = [['label' => 'Total', 'from' => $from, 'to' => $to]];
        if ($monthly) {
            $periods = [];
            $cursor = Carbon::parse($from)->startOfMonth();
            while ($cursor->lte(Carbon::parse($to))) {
                $pf = max($from, $cursor->toDateString());
                $pt = min($to, $cursor->copy()->endOfMonth()->toDateString());
                $periods[] = ['label' => $cursor->format('M Y'), 'from' => $pf, 'to' => $pt];
                $cursor->addMonth();
                if (count($periods) > 24) break;
            }
            $periods[] = ['label' => 'Total', 'from' => $from, 'to' => $to];
        }
        $columns = array_map(fn ($p) => $this->pnlAmounts($b, $p['from'], $p['to'], $basis), $periods);

        $sections = ['income' => [], 'cogs' => [], 'expense' => []];
        $ids = [];
        foreach ($columns as $c) $ids += array_flip(array_keys($c));
        foreach (array_keys($ids) as $id) {
            $a = $accounts[$id];
            $sec = $a->type === 'income' ? 'income' : ($a->subtype === 'cogs' ? 'cogs' : 'expense');
            $sections[$sec][] = ['account' => $a, 'values' => array_map(fn ($c) => $c[$id] ?? 0, $columns)];
        }
        foreach ($sections as &$rows) usort($rows, fn ($x, $y) => strcmp($x['account']->code, $y['account']->code));
        unset($rows);
        $sum = fn ($sec, $i) => array_sum(array_map(fn ($r) => $r['values'][$i], $sections[$sec]));
        $totals = [];
        foreach ($periods as $i => $p) {
            $inc = $sum('income', $i);
            $cogs = $sum('cogs', $i);
            $exp = $sum('expense', $i);
            $totals[] = ['income' => $inc, 'cogs' => $cogs, 'gross' => $inc - $cogs, 'expense' => $exp, 'net' => $inc - $cogs - $exp];
        }

        return compact('periods', 'sections', 'totals', 'basis');
    }

    public function fiscalYearStart(Business $b, Carbon $asOf): Carbon
    {
        $m = (int) ($b->fiscal_year_start_month ?: 1);
        $start = Carbon::create($asOf->year, $m, 1);

        return $start->gt($asOf) ? $start->subYear() : $start;
    }

    public function balanceSheet(Business $b, string $asOf): array
    {
        $accounts = $this->accounts($b);
        $bal = $this->balances($b, null, $asOf);
        $fy = $this->fiscalYearStart($b, Carbon::parse($asOf));
        $priorPnl = $this->pnlAmounts($b, '1900-01-01', $fy->copy()->subDay()->toDateString(), 'accrual');
        $currPnl = $this->pnlAmounts($b, $fy->toDateString(), $asOf, 'accrual');
        $net = fn (array $p) => array_sum(array_map(fn ($id, $v) => $accounts[$id]->type === 'income' ? $v : -$v, array_keys($p), $p));

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        foreach ($bal as $id => $v) {
            $a = $accounts[$id] ?? null;
            if (! $a || ! isset($sections[$a->type]) || $v === 0) continue;
            $sections[$a->type][] = ['account' => $a, 'amount' => $a->type === 'asset' ? $v : -$v];
        }
        foreach ($sections as &$rows) usort($rows, fn ($x, $y) => strcmp($x['account']->code, $y['account']->code));
        unset($rows);
        $retained = $net($priorPnl);
        $current = $net($currPnl);
        $assets = array_sum(array_column($sections['asset'], 'amount'));
        $liab = array_sum(array_column($sections['liability'], 'amount'));
        $equity = array_sum(array_column($sections['equity'], 'amount')) + $retained + $current;

        return [
            'sections' => $sections,
            'retained_computed' => $retained,
            'current_earnings' => $current,
            'fy_start' => $fy->toDateString(),
            'total_assets' => $assets,
            'total_liabilities' => $liab,
            'total_equity' => $equity,
            'balanced' => $assets === $liab + $equity,
        ];
    }

    public function trialBalance(Business $b, string $asOf): array
    {
        $accounts = $this->accounts($b);
        $rows = [];
        $dr = $cr = 0;
        foreach ($this->balances($b, null, $asOf) as $id => $v) {
            if ($v === 0) continue;
            $rows[] = ['account' => $accounts[$id], 'debit' => max($v, 0), 'credit' => max(-$v, 0)];
            $dr += max($v, 0);
            $cr += max(-$v, 0);
        }
        usort($rows, fn ($x, $y) => strcmp($x['account']->code, $y['account']->code));

        return ['rows' => $rows, 'debit' => $dr, 'credit' => $cr];
    }

    public function generalLedger(Business $b, ?int $accountId, string $from, string $to, string $basis = 'accrual'): array
    {
        $accounts = $this->accounts($b);
        $selected = $accountId ? [$accountId] : $accounts->keys()->all();
        $out = [];
        foreach ($selected as $id) {
            $a = $accounts[$id] ?? null;
            if (! $a) continue;
            if ($basis === 'cash') {
                $events = CashProjectionEvent::where('business_id', $b->id)->where('account_id', $id)->whereBetween('event_date', [$from, $to])->orderBy('event_date')->orderBy('id')->get();
                if ($events->isEmpty()) continue;
                $out[] = ['account' => $a, 'opening' => 0, 'cash' => true, 'lines' => $events, 'closing' => (int) $events->sum('amount_cents')];
                continue;
            }
            $opening = (int) JournalLine::where('business_id', $b->id)->where('account_id', $id)->where('entry_date', '<', $from)->sum(\Illuminate\Support\Facades\DB::raw('debit_cents - credit_cents'));
            $lines = JournalLine::with('entry')->where('business_id', $b->id)->where('account_id', $id)->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->limit(5000)->get();
            if ($lines->isEmpty() && $opening === 0) continue;
            $out[] = ['account' => $a, 'opening' => $opening, 'cash' => false, 'lines' => $lines, 'closing' => $opening + (int) $lines->sum(fn ($l) => $l->debit_cents - $l->credit_cents)];
        }

        return $out;
    }

    public function arAging(Business $b, string $asOf): array
    {
        $invoices = Invoice::with('client')->where('business_id', $b->id)->where('status', '!=', 'draft')
            ->whereDate('issue_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('voided_at')->orWhere('voided_at', '>', Carbon::parse($asOf)->endOfDay()))
            ->get();
        $asOfEnd = Carbon::parse($asOf)->endOfDay();
        $rows = [];
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($invoices as $inv) {
            $applied = (int) PaymentAllocation::where('business_id', $b->id)->where('invoice_id', $inv->id)
                ->whereDate('applied_date', '<=', $asOf)
                ->where(fn ($q) => $q->where('status', 'active')->orWhere('reversed_at', '>', $asOfEnd))
                ->sum('amount_cents');
            $open = $inv->total_cents - $applied;
            if ($open <= 0) continue;
            $days = $inv->due_date ? (int) $inv->due_date->diffInDays(Carbon::parse($asOf), false) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+')));
            $buckets[$bucket] += $open;
            $rows[] = ['invoice' => $inv, 'open' => $open, 'days' => max($days, 0), 'bucket' => $bucket];
        }
        usort($rows, fn ($x, $y) => $y['days'] <=> $x['days']);

        return ['rows' => $rows, 'buckets' => $buckets, 'total' => array_sum($buckets)];
    }

    public function apAging(Business $b, string $asOf): array
    {
        $bills = Expense::with('vendor')->where('business_id', $b->id)->where('kind', 'bill')->whereNull('voided_at')->whereDate('expense_date', '<=', $asOf)->get();
        $rows = [];
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($bills as $bill) {
            $paid = (int) VendorPayment::where('expense_id', $bill->id)->where('status', 'posted')->whereDate('payment_date', '<=', $asOf)->sum('amount_cents');
            $open = $bill->total_cents - $paid;
            if ($open <= 0) continue;
            $days = $bill->due_date ? (int) $bill->due_date->diffInDays(Carbon::parse($asOf), false) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+')));
            $buckets[$bucket] += $open;
            $rows[] = ['bill' => $bill, 'open' => $open, 'days' => max($days, 0), 'bucket' => $bucket];
        }

        return ['rows' => $rows, 'buckets' => $buckets, 'total' => array_sum($buckets)];
    }

    public function salesByClient(Business $b, string $from, string $to): array
    {
        $income = ChartAccount::where('business_id', $b->id)->where('type', 'income')->pluck('id');
        $rows = JournalLine::with('client')->where('business_id', $b->id)->whereIn('account_id', $income)->whereBetween('entry_date', [$from, $to])
            ->selectRaw('client_id, SUM(credit_cents) - SUM(debit_cents) as amt')->groupBy('client_id')->get()
            ->map(fn ($r) => ['label' => $r->client?->display_name ?? '(no client)', 'client_id' => $r->client_id, 'amount' => (int) $r->amt])
            ->sortByDesc('amount')->values()->all();

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))];
    }

    public function salesByItem(Business $b, string $from, string $to): array
    {
        $rows = \App\Models\InvoiceLine::query()->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoices.business_id', $b->id)->where('invoices.status', 'issued')->whereBetween('invoices.issue_date', [$from, $to])
            ->selectRaw('invoice_lines.name as label, SUM(invoice_lines.quantity) as qty, SUM(invoice_lines.net_cents) as amt')
            ->groupBy('invoice_lines.name')->orderByDesc('amt')->get()
            ->map(fn ($r) => ['label' => $r->label, 'qty' => (string) $r->qty, 'amount' => (int) $r->amt])->all();

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount')), 'note' => 'Issued, non-void invoices by issue date; amounts net of discounts, excluding tax and credit notes.'];
    }

    public function expensesBy(Business $b, string $from, string $to, string $by, string $basis = 'accrual'): array
    {
        $expenseIds = ChartAccount::where('business_id', $b->id)->where('type', 'expense')->pluck('id');
        if ($by === 'category') {
            $amounts = $this->pnlAmounts($b, $from, $to, $basis);
            $accounts = $this->accounts($b);
            $rows = collect($amounts)->filter(fn ($v, $id) => $accounts[$id]->type === 'expense')
                ->map(fn ($v, $id) => ['label' => $accounts[$id]->label(), 'account_id' => $id, 'amount' => $v])->sortByDesc('amount')->values()->all();
        } else {
            $rows = JournalLine::with('vendor')->where('business_id', $b->id)->whereIn('account_id', $expenseIds)->whereBetween('entry_date', [$from, $to])
                ->selectRaw('vendor_id, SUM(debit_cents) - SUM(credit_cents) as amt')->groupBy('vendor_id')->get()
                ->map(fn ($r) => ['label' => $r->vendor?->name ?? '(no vendor)', 'amount' => (int) $r->amt])->sortByDesc('amount')->values()->all();
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))];
    }

    public function salesTax(Business $b, string $from, string $to): array
    {
        $tax = $b->account('sales_tax');
        $lines = JournalLine::with('entry')->where('business_id', $b->id)->where('account_id', $tax->id)->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->get();
        $opening = -(int) JournalLine::where('business_id', $b->id)->where('account_id', $tax->id)->where('entry_date', '<', $from)->sum(\Illuminate\Support\Facades\DB::raw('debit_cents - credit_cents'));
        $collected = (int) $lines->filter(fn ($l) => $l->entry->source_type === 'invoice')->sum('credit_cents');
        $reversed = (int) $lines->filter(fn ($l) => $l->entry->source_type === 'credit_note')->sum('debit_cents');
        $paid = (int) $lines->filter(fn ($l) => ! in_array($l->entry->source_type, ['invoice', 'credit_note'], true))->sum(fn ($l) => $l->debit_cents - $l->credit_cents);
        $cashCollected = (int) CashProjectionEvent::where('business_id', $b->id)->where('account_id', $tax->id)->whereBetween('event_date', [$from, $to])->sum('amount_cents');
        $byRate = \App\Models\InvoiceTaxLine::query()->join('invoices', 'invoices.id', '=', 'invoice_tax_lines.invoice_id')
            ->where('invoices.business_id', $b->id)->where('invoices.status', 'issued')->whereBetween('invoices.issue_date', [$from, $to])
            ->selectRaw('invoice_tax_lines.name, invoice_tax_lines.rate, SUM(invoice_tax_lines.taxable_cents) as taxable, SUM(invoice_tax_lines.tax_cents) as tax')
            ->groupBy('invoice_tax_lines.name', 'invoice_tax_lines.rate')->get();

        return [
            'opening' => $opening,
            'invoiced' => $collected,
            'credited' => $reversed,
            'payments_and_adjustments' => $paid,
            'closing' => $opening + $collected - $reversed - $paid,
            'cash_collected' => $cashCollected,
            'by_rate' => $byRate,
            'lines' => $lines,
        ];
    }

    public function invoiceSummary(Business $b, string $from, string $to): array
    {
        $issued = Invoice::where('business_id', $b->id)->where('status', 'issued')->whereBetween('issue_date', [$from, $to]);
        $collected = (int) \App\Models\Payment::where('business_id', $b->id)->where('status', '!=', 'reversed')->whereBetween('received_date', [$from, $to])->sum('amount_cents');

        return [
            'issued_count' => (clone $issued)->count(),
            'issued_total' => (int) (clone $issued)->sum('total_cents'),
            'collected' => $collected,
            'outstanding' => (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->sum('balance_cents'),
            'overdue' => (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->whereDate('due_date', '<', $b->localToday())->sum('balance_cents'),
            'voided_count' => Invoice::where('business_id', $b->id)->where('status', 'void')->whereBetween('issue_date', [$from, $to])->count(),
        ];
    }

    public function recurringForecast(Business $b, int $months = 6): array
    {
        $calc = app(RecurrenceCalculator::class);
        $end = $b->localToday()->addMonths($months);
        $rows = [];
        $byMonth = [];
        foreach (RecurringSchedule::with('lines', 'client')->where('business_id', $b->id)->where('status', 'active')->get() as $s) {
            $amount = $calc->scheduleAmountCents($s);
            foreach ($calc->upcoming($s, 60) as $date) {
                if ($date->gt($end)) break;
                $rows[] = ['schedule' => $s, 'date' => $date, 'amount' => $amount];
                $byMonth[$date->format('Y-m')] = ($byMonth[$date->format('Y-m')] ?? 0) + $amount;
            }
        }
        usort($rows, fn ($x, $y) => $x['date'] <=> $y['date']);
        ksort($byMonth);

        return ['rows' => $rows, 'by_month' => $byMonth, 'total' => array_sum($byMonth)];
    }

    public function contractValues(Business $b): array
    {
        $rows = Contract::with('client')->where('business_id', $b->id)->whereNotIn('status', ['draft', 'voided'])->get()->map(function ($c) {
            $inv = $c->invoicedCents();
            $col = $c->collectedCents();

            return ['contract' => $c, 'committed' => $c->committed_value_cents, 'invoiced' => $inv, 'collected' => $col, 'unbilled' => max(0, $c->committed_value_cents - $inv)];
        })->all();

        return ['rows' => $rows];
    }

    public function dashboard(Business $b, string $from, string $to): array
    {
        $basis = $b->reporting_basis ?: 'accrual';
        $pnl = $this->profitAndLoss($b, $from, $to, $basis);
        $collected = (int) \App\Models\Payment::where('business_id', $b->id)->where('status', '!=', 'reversed')->whereDate('received_date', '>=', $from)->whereDate('received_date', '<=', $to)->sum('amount_cents');
        $issued = (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->whereDate('issue_date', '>=', $from)->whereDate('issue_date', '<=', $to)->sum('total_cents');
        $outstanding = (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->sum('balance_cents');
        $overdue = (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->whereDate('due_date', '<', $b->localToday())->sum('balance_cents');

        return [
            'basis' => $basis,
            'collected' => $collected,
            'issued' => $issued,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'income' => $pnl['totals'][0]['income'],
            'expenses' => $pnl['totals'][0]['cogs'] + $pnl['totals'][0]['expense'],
            'net' => $pnl['totals'][0]['net'],
        ];
    }
}
