@extends('layouts.app')
@section('title', $project->name)
@section('content')
@include('expenses._tabs', ['active' => 'projects'])
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $project->name }} <span class="chip {{ $project->status === 'open' ? 'chip-green' : 'chip-gray' }}">{{ ucfirst($project->status) }}</span></h1>
        <p class="text-sm text-slate-500">
            @if($project->client)<a href="{{ $bu }}/clients/{{ $project->client_id }}">{{ $project->client->display_name }}</a>@else General business @endif
            @if($project->start_date) · {{ $project->start_date->format('M j, Y') }}{{ $project->end_date && ! $project->end_date->equalTo($project->start_date) ? ' – '.$project->end_date->format('M j, Y') : '' }}@endif
        </p>
    </div>
    @if ($ctx->can('expenses.manage'))
    <div class="flex gap-2">
        <a class="btn" href="{{ $bu }}/projects/{{ $project->id }}/edit">Edit</a>
        <a class="btn" href="{{ $bu }}/mileage/new?project={{ $project->id }}">+ Mileage</a>
        <a class="btn btn-primary" href="{{ $bu }}/expenses/new?project={{ $project->id }}{{ $project->client_id ? '&client='.$project->client_id : '' }}">+ Expense</a>
    </div>
    @endif
</div>
@if ($project->description)<p class="mt-3 max-w-3xl text-sm">{{ $project->description }}</p>@endif
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="card overflow-x-auto">
            <div class="card-head"><h2>Expenses</h2><span class="text-sm">Total <strong>@money($expenses->sum('total_cents'))</strong></span></div>
            <table class="tbl"><thead><tr><th>Date</th><th>Vendor</th><th>Description</th><th class="num">Amount</th></tr></thead><tbody>
            @forelse ($expenses as $e)
                <tr><td><a href="{{ $bu }}/expenses/{{ $e->id }}">{{ $e->expense_date->format('M j, Y') }}</a></td><td>{{ $e->vendor?->name ?? '—' }}</td>
                    <td>{{ $e->description }} @include('expenses._chips', ['e' => $e])</td><td class="num">@money($e->total_cents)</td></tr>
            @empty
                <tr><td colspan="4" class="text-slate-500">No expenses in this project yet. Assign existing expenses from their edit page.</td></tr>
            @endforelse
            </tbody></table>
        </div>
        @if ($trips->isNotEmpty())
        <div class="card overflow-x-auto">
            <div class="card-head"><h2>Mileage</h2><span class="text-sm">{{ number_format($trips->sum(fn ($t) => $t->totalMiles()), 1) }} mi · @money($trips->sum('amount_cents'))</span></div>
            <table class="tbl"><thead><tr><th>Date</th><th>Route</th><th>Purpose</th><th class="num">Miles</th><th class="num">Amount</th></tr></thead><tbody>
            @foreach ($trips as $t)
                <tr><td>{{ $t->trip_date->format('M j, Y') }}</td><td class="text-sm">{{ $t->from_location }} → {{ $t->to_location }}{{ $t->round_trip ? ' ↺' : '' }}</td><td>{{ $t->purpose }}</td><td class="num">{{ $t->totalMiles() }}</td><td class="num">@money($t->amount_cents)</td></tr>
            @endforeach
            </tbody></table>
        </div>
        @endif
    </div>
    <aside class="space-y-4">
        <div class="card overflow-x-auto"><div class="card-head"><h2>By category</h2></div>
            <table class="tbl"><tbody>
            @forelse ($byCategory as $row)
                <tr><td>{{ $row['account']->name }}</td><td class="num">@money($row['cents'])</td></tr>
            @empty
                <tr><td class="text-slate-500">—</td></tr>
            @endforelse
            </tbody></table>
        </div>
        @if ($unbilled > 0)<div class="card card-body text-sm">Unbilled billable costs: <strong>@money($unbilled)</strong></div>@endif
    </aside>
</div>
@endsection
