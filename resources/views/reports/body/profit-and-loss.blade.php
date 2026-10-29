@php $M = fn ($c) => \App\Support\Money::format((int) $c); $gl = fn ($id, $p) => $bu.'/reports/general-ledger?account='.$id.'&range=custom&from='.$p['from'].'&to='.$p['to'].'&basis='.$data['basis']; @endphp
<table class="tbl">
    <thead><tr><th>Account</th>@foreach ($data['periods'] as $p)<th class="num">{{ $p['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach (['income' => 'Income', 'cogs' => 'Cost of goods sold', 'expense' => 'Expenses'] as $sec => $label)
        @if (count($data['sections'][$sec]) || $sec !== 'cogs')
        <tr class="section"><td colspan="{{ count($data['periods']) + 1 }}" class="bg-slate-50 font-semibold">{{ $label }}</td></tr>
        @foreach ($data['sections'][$sec] as $row)
            <tr><td class="pl-6">{{ $row['account']->label() }}</td>
                @foreach ($row['values'] as $i => $v)<td class="num"><a href="{{ $gl($row['account']->id, $data['periods'][$i]) }}">{{ $M($v) }}</a></td>@endforeach</tr>
        @endforeach
        @if (! count($data['sections'][$sec]))<tr><td colspan="{{ count($data['periods']) + 1 }}" class="pl-6 text-slate-500">None</td></tr>@endif
        @endif
    @endforeach
    </tbody>
    <tfoot>
        @foreach (['income' => 'Total income', 'cogs' => 'Total cost of goods sold', 'gross' => 'Gross profit', 'expense' => 'Total expenses', 'net' => 'Net profit'] as $k => $label)
            <tr class="total {{ $k === 'net' ? 'text-base' : '' }}"><td class="px-3 py-2 font-semibold">{{ $label }}</td>@foreach ($data['totals'] as $t)<td class="num px-3 py-2 font-semibold {{ $k === 'net' && $t['net'] < 0 ? 'text-red-700' : '' }}">{{ $M($t[$k]) }}</td>@endforeach</tr>
        @endforeach
    </tfoot>
</table>
<p class="hint p-4">Excludes owner contributions/draws, transfers, loan principal, customer deposits not yet applied, and sales tax collected (a liability).</p>
