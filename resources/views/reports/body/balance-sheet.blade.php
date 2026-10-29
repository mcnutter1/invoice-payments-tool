@php $M = fn ($c) => \App\Support\Money::format((int) $c); $gl = fn ($id) => $bu.'/reports/general-ledger?account='.$id.'&range=custom&from=2000-01-01&to='.$meta['asOf']; @endphp
<table class="tbl">
    <tbody>
    @foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $sec => $label)
        <tr class="section"><td colspan="2" class="bg-slate-50 font-semibold">{{ $label }}</td></tr>
        @foreach ($data['sections'][$sec] as $row)
            <tr><td class="pl-6">{{ $row['account']->label() }}</td><td class="num"><a href="{{ $gl($row['account']->id) }}">{{ $M($row['amount']) }}</a></td></tr>
        @endforeach
        @if ($sec === 'equity')
            <tr><td class="pl-6">Retained earnings (prior fiscal years, computed)</td><td class="num">{{ $M($data['retained_computed']) }}</td></tr>
            <tr><td class="pl-6">Net income, fiscal year from {{ $data['fy_start'] }}</td><td class="num"><a href="{{ $bu }}/reports/profit-and-loss?range=custom&from={{ $data['fy_start'] }}&to={{ $meta['asOf'] }}&basis=accrual">{{ $M($data['current_earnings']) }}</a></td></tr>
        @endif
        <tr class="total"><td class="font-semibold">Total {{ strtolower($label) }}</td><td class="num font-semibold">{{ $M($sec === 'asset' ? $data['total_assets'] : ($sec === 'liability' ? $data['total_liabilities'] : $data['total_equity'])) }}</td></tr>
    @endforeach
    <tr class="total"><td class="font-semibold">Total liabilities &amp; equity</td><td class="num font-semibold">{{ $M($data['total_liabilities'] + $data['total_equity']) }}</td></tr>
    </tbody>
</table>
<p class="p-4 text-sm {{ $data['balanced'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $data['balanced'] ? '✓ Balanced: assets equal liabilities plus equity.' : '⚠ Out of balance — contact support.' }}</p>
