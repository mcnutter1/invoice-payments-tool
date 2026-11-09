@extends('layouts.app')
@section('title', 'Mileage')
@section('content')
@include('expenses._tabs', ['active' => 'mileage'])
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Mileage</h1>
    @if ($ctx->can('expenses.manage'))<a href="{{ $bu }}/mileage/new" class="btn btn-primary">+ Trip</a>@endif
</div>
<p class="mt-1 text-sm text-slate-500">
    Business miles are expensed at the IRS standard mileage rate in effect on the trip date
    @if ($latest)(currently {{ rtrim(rtrim(number_format($latest->business_rate_millis / 10, 1), '0'), '.') }}¢/mile since {{ $latest->effective_from->format('M j, Y') }})@endif.
    Rates are checked against irs.gov weekly.
</p>
@if ($currentProvisional)
    <div class="alert alert-warn mt-3">The IRS hasn’t published a {{ $biz->localToday()->year }} rate yet (or it hasn’t been picked up). Trips this year use the {{ $latest?->effective_from->year }} rate provisionally and can be re-priced once it’s published.</div>
@endif
@if ($needsReprice > 0 && $ctx->can('expenses.manage'))
    <form method="post" action="{{ $bu }}/mileage/reprice" class="alert alert-info mt-3 flex flex-wrap items-center justify-between gap-2">@csrf
        <span>{{ $needsReprice }} trip{{ $needsReprice === 1 ? '' : 's' }} recorded at a rate that differs from the published IRS rate for the date.</span>
        <button class="btn btn-sm">Re-price now</button>
    </form>
@endif
<form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="from">From</label><input id="from" type="date" name="from" class="input" value="{{ $from }}"></div>
    <div><label class="label" for="to">To</label><input id="to" type="date" name="to" class="input" value="{{ $to }}"></div>
    <div><label class="label" for="client">Client</label><select id="client" name="client" class="input"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
    <div><label class="label" for="project">Project</label><select id="project" name="project" class="input"><option value="">All</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
    <button class="btn">Filter</button>
</form>
<div class="mt-4 grid gap-3 sm:grid-cols-3">
    <div class="card card-body"><div class="text-xs text-slate-500">Trips</div><div class="text-2xl font-semibold">{{ $trips->count() }}</div></div>
    <div class="card card-body"><div class="text-xs text-slate-500">Business miles</div><div class="text-2xl font-semibold">{{ number_format($trips->sum(fn ($t) => $t->totalMiles()), 1) }}</div></div>
    <div class="card card-body"><div class="text-xs text-slate-500">Deduction</div><div class="text-2xl font-semibold">@money($trips->sum('amount_cents'))</div></div>
</div>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Route</th><th>Purpose</th><th>Client / project</th><th class="num">Miles</th><th class="num">Rate</th><th class="num">Amount</th><th></th></tr></thead><tbody>
    @forelse ($trips as $t)
        <tr>
            <td class="whitespace-nowrap">{{ $t->trip_date->format('M j, Y') }}</td>
            <td class="text-sm">{{ $t->from_location ?: '—' }} → {{ $t->to_location ?: '—' }}@if($t->round_trip) <span class="chip chip-gray">Round trip</span>@endif</td>
            <td>{{ $t->purpose }}@if($t->vehicle)<div class="text-xs text-slate-500">{{ $t->vehicle }}</div>@endif</td>
            <td class="text-sm">@if($t->client)<a href="{{ $bu }}/clients/{{ $t->client_id }}">{{ $t->client->display_name }}</a>@else<span class="text-slate-400">General</span>@endif @if($t->project)<br><a href="{{ $bu }}/projects/{{ $t->project_id }}" class="chip chip-blue">{{ $t->project->name }}</a>@endif</td>
            <td class="num">{{ $t->totalMiles() }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($t->rate_millis / 10, 1), '0'), '.') }}¢</td>
            <td class="num">@if($t->expense_id)<a href="{{ $bu }}/expenses/{{ $t->expense_id }}">@money($t->amount_cents)</a>@else @money($t->amount_cents) @endif</td>
            <td class="text-right whitespace-nowrap">@if ($ctx->can('expenses.manage'))<a class="btn btn-ghost btn-sm" href="{{ $bu }}/mileage/{{ $t->id }}/edit">Edit</a>@endif</td>
        </tr>
    @empty
        <tr><td colspan="8"><div class="empty m-4">No trips in this period.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
