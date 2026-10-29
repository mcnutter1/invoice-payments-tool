@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<p class="p-4 text-sm text-slate-600">Forecast of scheduled billing — <strong>not recognized revenue</strong>. Amounts include tax at current rates.</p>
<table class="tbl">
    <thead><tr><th>Month</th><th class="num">Scheduled</th></tr></thead>
    <tbody>
    @forelse ($data['by_month'] as $m => $v)<tr><td>{{ \Illuminate\Support\Carbon::parse($m.'-01')->format('M Y') }}</td><td class="num">{{ $M($v) }}</td></tr>
    @empty <tr><td colspan="2" class="text-slate-500">No active schedules.</td></tr> @endforelse
    <tr class="total"><td class="font-semibold">Total</td><td class="num font-semibold">{{ $M($data['total']) }}</td></tr>
    </tbody>
</table>
<table class="tbl mt-4">
    <thead><tr><th>Date</th><th>Schedule</th><th>Client</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @foreach ($data['rows'] as $r)<tr><td>{{ $r['date']->format('Y-m-d') }}</td><td><a href="{{ $bu }}/recurring/{{ $r['schedule']->id }}">{{ $r['schedule']->name }}</a></td><td>{{ $r['schedule']->client->display_name }}</td><td class="num">{{ $M($r['amount']) }}</td></tr>@endforeach
    </tbody>
</table>
