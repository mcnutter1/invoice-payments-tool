<?php

namespace App\Services\Reports;

use App\Support\Money;

/** Flattens report data structures into CSV rows (amounts as plain decimals). */
class ReportCsv
{
    private function m(int $c): string
    {
        return Money::toDecimalString($c);
    }

    public function rows(string $report, array $d, array $meta): array
    {
        $period = ['Period', $meta['from'], $meta['to']];
        $asOf = ['As of', $meta['asOf']];

        return match ($report) {
            'profit-and-loss' => $this->pnl($d, $period),
            'balance-sheet' => $this->bs($d, $asOf),
            'trial-balance' => array_merge([$asOf, ['Code', 'Account', 'Debit', 'Credit']],
                array_map(fn ($r) => [$r['account']->code, $r['account']->name, $this->m($r['debit']), $this->m($r['credit'])], $d['rows']),
                [['', 'Total', $this->m($d['debit']), $this->m($d['credit'])]]),
            'general-ledger' => $this->gl($d, $period),
            'ar-aging' => array_merge([$asOf, ['Invoice', 'Client', 'Due', 'Days past due', 'Bucket', 'Open']],
                array_map(fn ($r) => [$r['invoice']->number, $r['invoice']->client->display_name, $r['invoice']->due_date?->toDateString(), $r['days'], $r['bucket'], $this->m($r['open'])], $d['rows']),
                [[], ['Bucket', 'Amount']], array_map(fn ($k, $v) => [$k, $this->m($v)], array_keys($d['buckets']), $d['buckets'])),
            'ap-aging' => array_merge([$asOf, ['Bill', 'Vendor', 'Due', 'Days past due', 'Bucket', 'Open']],
                array_map(fn ($r) => [$r['bill']->reference ?: '#'.$r['bill']->id, $r['bill']->vendor?->name, $r['bill']->due_date?->toDateString(), $r['days'], $r['bucket'], $this->m($r['open'])], $d['rows'])),
            'invoice-summary' => [$period, ['Metric', 'Value'], ['Invoices issued', $d['issued_count']], ['Issued total', $this->m($d['issued_total'])],
                ['Payments received', $this->m($d['collected'])], ['Outstanding now', $this->m($d['outstanding'])], ['Overdue now', $this->m($d['overdue'])]],
            'sales-by-client', 'expenses-by-category', 'expenses-by-vendor' => array_merge([$period, ['Name', 'Amount']],
                array_map(fn ($r) => [$r['label'], $this->m($r['amount'])], $d['rows']), [['Total', $this->m($d['total'])]]),
            'sales-by-item' => array_merge([$period, ['Item', 'Quantity', 'Amount']],
                array_map(fn ($r) => [$r['label'], $r['qty'], $this->m($r['amount'])], $d['rows']), [['Total', '', $this->m($d['total'])]]),
            'sales-tax' => array_merge([$period, ['Opening liability', $this->m($d['opening'])], ['Tax on issued invoices', $this->m($d['invoiced'])],
                ['Tax reversed by credit notes', $this->m($d['credited'])], ['Payments & adjustments', $this->m($d['payments_and_adjustments'])],
                ['Closing liability', $this->m($d['closing'])], ['Tax collected in cash (management view)', $this->m($d['cash_collected'])], [], ['Rate', 'Name', 'Taxable', 'Tax']],
                array_map(fn ($r) => [(string) $r->rate, $r->name, $this->m((int) $r->taxable), $this->m((int) $r->tax)], $d['by_rate']->all())),
            'recurring-forecast' => array_merge([['Forecast (not recognized revenue)'], ['Date', 'Schedule', 'Client', 'Amount']],
                array_map(fn ($r) => [$r['date']->toDateString(), $r['schedule']->name, $r['schedule']->client->display_name, $this->m($r['amount'])], $d['rows'])),
            'contract-values' => array_merge([['Contract value (not recognized revenue)'], ['Number', 'Title', 'Client', 'Status', 'Committed', 'Invoiced', 'Collected', 'Unbilled']],
                array_map(fn ($r) => [$r['contract']->number, $r['contract']->title, $r['contract']->client->display_name, $r['contract']->status,
                    $this->m($r['committed']), $this->m($r['invoiced']), $this->m($r['collected']), $this->m($r['unbilled'])], $d['rows'])),
            default => [],
        };
    }

    private function pnl(array $d, array $period): array
    {
        $head = array_merge(['Code', 'Account'], array_column($d['periods'], 'label'));
        $rows = [$period, $head];
        foreach (['income' => 'Income', 'cogs' => 'Cost of goods sold', 'expense' => 'Expenses'] as $k => $label) {
            $rows[] = [$label];
            foreach ($d['sections'][$k] as $r) {
                $rows[] = array_merge([$r['account']->code, $r['account']->name], array_map(fn ($v) => $this->m($v), $r['values']));
            }
        }
        foreach (['income' => 'Total income', 'gross' => 'Gross profit', 'expense' => 'Total expenses', 'net' => 'Net profit'] as $k => $label) {
            $rows[] = array_merge(['', $label], array_map(fn ($t) => $this->m($t[$k]), $d['totals']));
        }

        return $rows;
    }

    private function bs(array $d, array $asOf): array
    {
        $rows = [$asOf, ['Code', 'Account', 'Amount']];
        foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
            $rows[] = [$label];
            foreach ($d['sections'][$k] as $r) $rows[] = [$r['account']->code, $r['account']->name, $this->m($r['amount'])];
        }
        $rows[] = ['', 'Retained earnings (prior years, computed)', $this->m($d['retained_computed'])];
        $rows[] = ['', 'Net income (current fiscal year)', $this->m($d['current_earnings'])];
        $rows[] = ['', 'Total assets', $this->m($d['total_assets'])];
        $rows[] = ['', 'Total liabilities', $this->m($d['total_liabilities'])];
        $rows[] = ['', 'Total equity', $this->m($d['total_equity'])];

        return $rows;
    }

    private function gl(array $d, array $period): array
    {
        $rows = [$period, ['Account', 'Date', 'Entry', 'Memo', 'Debit', 'Credit', 'Amount']];
        foreach ($d['accounts'] as $a) {
            $rows[] = [$a['account']->label(), '', '', 'Opening', '', '', $this->m($a['opening'])];
            foreach ($a['lines'] as $l) {
                $rows[] = $a['cash']
                    ? [$a['account']->label(), $l->event_date->toDateString(), $l->source_key, $l->description, '', '', $this->m($l->amount_cents)]
                    : [$a['account']->label(), $l->entry_date->toDateString(), $l->journal_entry_id, $l->entry->memo, $this->m($l->debit_cents), $this->m($l->credit_cents), ''];
            }
            $rows[] = [$a['account']->label(), '', '', 'Closing', '', '', $this->m($a['closing'])];
        }

        return $rows;
    }
}
