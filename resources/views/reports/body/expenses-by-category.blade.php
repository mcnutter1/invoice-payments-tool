@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <thead><tr><th>Category</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td><a href="{{ $bu }}/reports/general-ledger?account={{ $r['account_id'] }}&range=custom&from={{ $meta['from'] }}&to={{ $meta['to'] }}&basis={{ $meta['basis'] }}">{{ $r['label'] }}</a></td><td class="num">{{ $M($r['amount']) }}</td></tr>
    @empty <tr><td colspan="2" class="text-slate-500">No expenses in this period.</td></tr> @endforelse
    <tr class="total"><td class="font-semibold">Total</td><td class="num font-semibold">{{ $M($data['total']) }}</td></tr>
    </tbody>
</table>
