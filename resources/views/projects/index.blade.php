@extends('layouts.app')
@section('title', 'Projects')
@section('content')
@include('expenses._tabs', ['active' => 'projects'])
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Projects</h1>
    @if ($ctx->can('expenses.manage'))<a href="{{ $bu }}/projects/new" class="btn btn-primary">+ Project</a>@endif
</div>
<p class="mt-1 text-sm text-slate-500">Group expenses and mileage for a client engagement or trip, e.g. “NYC meeting with Mavericks Group”. Expenses without a client or project count as general business expenses.</p>
<form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="status">Status</label><select id="status" name="status" class="input">@foreach (['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><label class="label" for="client">Client</label><select id="client" name="client" class="input"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
    <button class="btn">Filter</button>
</form>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Project</th><th>Client</th><th>Dates</th><th class="num">Expenses</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
    @forelse ($projects as $p)
        <tr>
            <td><a href="{{ $bu }}/projects/{{ $p->id }}" class="font-medium">{{ $p->name }}</a></td>
            <td>@if($p->client)<a href="{{ $bu }}/clients/{{ $p->client_id }}">{{ $p->client->display_name }}</a>@else<span class="text-slate-400">General business</span>@endif</td>
            <td class="text-sm">{{ $p->start_date?->format('M j, Y') }}{{ $p->end_date && ! $p->end_date->equalTo($p->start_date) ? ' – '.$p->end_date->format('M j, Y') : '' }}</td>
            <td class="num">{{ $totals[$p->id]->n ?? 0 }}</td>
            <td class="num">@money((int) ($totals[$p->id]->cents ?? 0))</td>
            <td><span class="chip {{ $p->status === 'open' ? 'chip-green' : 'chip-gray' }}">{{ ucfirst($p->status) }}</span></td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="empty m-4">No projects yet.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
