@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<p class="p-4 text-sm text-slate-600">Contract value is a commitment, <strong>not recognized revenue</strong>. Invoiced = issued, non-void invoices linked to the agreement; collected = confirmed payments applied to them; unbilled = committed − invoiced.</p>
<table class="tbl">
    <thead><tr><th>Agreement</th><th>Client</th><th>Status</th><th class="num">Committed</th><th class="num">Invoiced</th><th class="num">Collected</th><th class="num">Unbilled</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td><a href="{{ $bu }}/contracts/{{ $r['contract']->id }}">{{ $r['contract']->number }} · {{ $r['contract']->title }}</a></td><td>{{ $r['contract']->client->display_name }}</td><td>{{ $r['contract']->status }}</td>
            <td class="num">{{ $M($r['committed']) }}</td><td class="num">{{ $M($r['invoiced']) }}</td><td class="num">{{ $M($r['collected']) }}</td><td class="num">{{ $M($r['unbilled']) }}</td></tr>
    @empty <tr><td colspan="7" class="text-slate-500">No agreements.</td></tr> @endforelse
    </tbody>
</table>
