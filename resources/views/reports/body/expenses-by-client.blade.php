@php $M = fn ($c) => \App\Support\Money::format((int) $c); $isProject = $data['by'] === 'project'; @endphp
<table class="tbl">
    <thead><tr><th>{{ $isProject ? 'Project' : 'Client' }}</th><th class="num">Expenses</th><th class="num">Billable</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @forelse ($data['rows'] as $r)
        <tr>
            <td>@if($r['id'])<a href="{{ $bu }}/{{ $isProject ? 'projects' : 'clients' }}/{{ $r['id'] }}">{{ $r['label'] }}</a>@else<span class="text-slate-600">{{ $r['label'] }}</span>@endif</td>
            <td class="num"><a href="{{ $bu }}/expenses?{{ $isProject ? 'project' : 'client' }}={{ $r['id'] ?? 'none' }}&from={{ $meta['from'] }}&to={{ $meta['to'] }}">{{ $r['count'] }}</a></td>
            <td class="num">{{ $r['billable'] ? $M($r['billable']) : '—' }}</td>
            <td class="num">{{ $M($r['amount']) }}</td>
        </tr>
    @empty <tr><td colspan="4" class="text-slate-500">No expenses in this period.</td></tr> @endforelse
    <tr class="total"><td class="font-semibold">Total</td><td></td><td></td><td class="num font-semibold">{{ $M($data['total']) }}</td></tr>
    </tbody>
</table>
@if (!empty($data['note']))<p class="mt-2 text-xs text-slate-500">{{ $data['note'] }}</p>@endif
