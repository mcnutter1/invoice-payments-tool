@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
@include('expenses._tabs', ['active' => 'expenses'])
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Expenses &amp; bills</h1>
    <div class="flex gap-2">
        <a href="{{ $bu }}/vendors" class="btn">Vendors</a>
        @if ($ctx->can('expenses.manage'))<a href="{{ $bu }}/expenses/import" class="btn">Import CSV</a><a href="{{ $bu }}/mileage/new" class="btn">+ Mileage</a><a href="{{ $bu }}/expenses/new?kind=bill" class="btn">+ Bill</a><a href="{{ $bu }}/expenses/new" class="btn btn-primary">+ Expense</a>@endif
    </div>
</div>
@if ($openBills > 0)<p class="mt-2 text-sm">Unpaid bills (A/P): <a href="?kind=bill&status=unpaid" class="font-semibold">@money($openBills)</a></p>@endif
<form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="kind">Type</label><select id="kind" name="kind" class="input"><option value="">All</option><option value="expense" @selected(request('kind') === 'expense')>Paid expenses</option><option value="bill" @selected(request('kind') === 'bill')>Bills</option></select></div>
    <div><label class="label" for="vendor">Vendor</label><select id="vendor" name="vendor" class="input"><option value="">All</option>@foreach ($vendors as $v)<option value="{{ $v->id }}" @selected(request('vendor') == $v->id)>{{ $v->name }}</option>@endforeach</select></div>
    <div><label class="label" for="client">Client</label><select id="client" name="client" class="input"><option value="">All</option><option value="none" @selected(request('client') === 'none')>General business</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
    <div><label class="label" for="project">Project</label><select id="project" name="project" class="input"><option value="">All</option><option value="none" @selected(request('project') === 'none')>No project</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
    <div><label class="label" for="source">Source</label><select id="source" name="source" class="input"><option value="">All</option>@foreach (\App\Models\Expense::SOURCES as $k => $label)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $label }}</option>@endforeach<option value="edited" @selected(request('source') === 'edited')>Edited</option></select></div>
    @if (request('batch'))<input type="hidden" name="batch" value="{{ (int) request('batch') }}">@endif
    <div><label class="label" for="from">From</label><input id="from" type="date" name="from" class="input" value="{{ request('from') }}"></div>
    <div><label class="label" for="to">To</label><input id="to" type="date" name="to" class="input" value="{{ request('to') }}"></div>
    <button class="btn">Filter</button>
</form>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Vendor</th><th>Category</th><th>Description</th><th>Client / project</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    @forelse ($expenses as $e)
        <tr @class(['opacity-60' => $e->voided_at])>
            <td><a href="{{ $bu }}/expenses/{{ $e->id }}">{{ $e->expense_date->format('M j, Y') }}</a></td>
            <td>{{ $e->vendor?->name ?? '—' }}</td>
            <td>{{ $e->lines->map(fn ($l) => $l->account->name)->unique()->implode(', ') }}</td>
            <td>{{ $e->description }} @include('expenses._chips', ['e' => $e])</td>
            <td class="text-sm">@if($e->client)<a href="{{ $bu }}/clients/{{ $e->client_id }}">{{ $e->client->display_name }}</a>@else<span class="text-slate-400">General</span>@endif @if($e->project)<br><a href="{{ $bu }}/projects/{{ $e->project_id }}" class="chip chip-blue">{{ $e->project->name }}</a>@endif</td>
            <td class="num">@money($e->total_cents)</td>
            <td>{{ $e->kind === 'bill' ? 'Bill · ' : '' }}@include('partials.status', ['s' => $e->status])</td>
        </tr>
    @empty
        <tr><td colspan="7"><div class="empty m-4">No expenses yet.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
@endsection
