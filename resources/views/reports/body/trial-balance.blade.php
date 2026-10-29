@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
    <tbody>
    @foreach ($data['rows'] as $row)
        <tr><td><a href="{{ $bu }}/reports/general-ledger?account={{ $row['account']->id }}&range=custom&from=2000-01-01&to={{ $meta['asOf'] }}">{{ $row['account']->label() }}</a></td>
            <td class="num">{{ $row['debit'] ? $M($row['debit']) : '' }}</td><td class="num">{{ $row['credit'] ? $M($row['credit']) : '' }}</td></tr>
    @endforeach
    <tr class="total"><td class="font-semibold">Total</td><td class="num font-semibold">{{ $M($data['debit']) }}</td><td class="num font-semibold">{{ $M($data['credit']) }}</td></tr>
    </tbody>
</table>
<p class="p-4 text-sm {{ $data['debit'] === $data['credit'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $data['debit'] === $data['credit'] ? '✓ Debits equal credits.' : '⚠ Debits and credits differ.' }}</p>
