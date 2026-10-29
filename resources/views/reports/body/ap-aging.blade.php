@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <thead><tr>@foreach ($data['buckets'] as $k => $v)<th class="num">{{ $k === 'current' ? 'Current' : $k.' days' }}</th>@endforeach<th class="num">Total</th></tr></thead>
    <tbody><tr>@foreach ($data['buckets'] as $v)<td class="num font-semibold">{{ $M($v) }}</td>@endforeach<td class="num font-semibold">{{ $M($data['total']) }}</td></tr></tbody>
</table>
<table class="tbl mt-4">
    <thead><tr><th>Bill</th><th>Vendor</th><th>Due</th><th class="num">Days past due</th><th class="num">Open</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr><td><a href="{{ $bu }}/expenses/{{ $r['bill']->id }}">{{ $r['bill']->reference ?: '#'.$r['bill']->id }}</a></td><td>{{ $r['bill']->vendor?->name }}</td><td>{{ $r['bill']->due_date?->format('Y-m-d') }}</td><td class="num">{{ $r['days'] }}</td><td class="num">{{ $M($r['open']) }}</td></tr>
    @empty <tr><td colspan="5" class="text-slate-500">No unpaid bills.</td></tr> @endforelse
    </tbody>
</table>
