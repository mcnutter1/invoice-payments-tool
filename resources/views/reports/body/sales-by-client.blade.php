@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <thead><tr><th>Client</th><th class="num">Revenue (accrual, net of credits)</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td>@if($r['client_id'])<a href="{{ $bu }}/clients/{{ $r['client_id'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td><td class="num">{{ $M($r['amount']) }}</td></tr>
    @empty <tr><td colspan="2" class="text-slate-500">No revenue in this period.</td></tr> @endforelse
    <tr class="total"><td class="font-semibold">Total</td><td class="num font-semibold">{{ $M($data['total']) }}</td></tr>
    </tbody>
</table>
