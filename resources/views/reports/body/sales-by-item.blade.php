@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <thead><tr><th>Product / service</th><th class="num">Quantity</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td>{{ $r['label'] }}</td><td class="num">{{ rtrim(rtrim($r['qty'], '0'), '.') }}</td><td class="num">{{ $M($r['amount']) }}</td></tr>
    @empty <tr><td colspan="3" class="text-slate-500">No sales in this period.</td></tr> @endforelse
    <tr class="total"><td class="font-semibold" colspan="2">Total</td><td class="num font-semibold">{{ $M($data['total']) }}</td></tr>
    </tbody>
</table>
<p class="hint p-4">{{ $data['note'] }}</p>
