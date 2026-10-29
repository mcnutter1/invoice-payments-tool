@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Expenses &amp; bills</h1>
    <div class="flex gap-2">
        <a href="{{ $bu }}/vendors" class="btn">Vendors</a>
        @if ($ctx->can('expenses.manage'))<a href="{{ $bu }}/expenses/new?kind=bill" class="btn">+ Bill</a><a href="{{ $bu }}/expenses/new" class="btn btn-primary">+ Expense</a>@endif
    </div>
</div>
@if ($openBills > 0)<p class="mt-2 text-sm">Unpaid bills (A/P): <a href="?kind=bill&status=unpaid" class="font-semibold">@money($openBills)</a></p>@endif
<form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="kind">Type</label><select id="kind" name="kind" class="input"><option value="">All</option><option value="expense" @selected(request('kind') === 'expense')>Paid expenses</option><option value="bill" @selected(request('kind') === 'bill')>Bills</option></select></div>
    <div><label class="label" for="vendor">Vendor</label><select id="vendor" name="vendor" class="input"><option value="">All</option>@foreach ($vendors as $v)<option value="{{ $v->id }}" @selected(request('vendor') == $v->id)>{{ $v->name }}</option>@endforeach</select></div>
    <div><label class="label" for="from">From</label><input id="from" type="date" name="from" class="input" value="{{ request('from') }}"></div>
    <div><label class="label" for="to">To</label><input id="to" type="date" name="to" class="input" value="{{ request('to') }}"></div>
    <button class="btn">Filter</button>
</form>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Vendor</th><th>Category</th><th>Description</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    @forelse ($expenses as $e)
        <tr>
            <td><a href="{{ $bu }}/expenses/{{ $e->id }}">{{ $e->expense_date->format('M j, Y') }}</a></td>
            <td>{{ $e->vendor?->name ?? '—' }}</td>
            <td>{{ $e->lines->map(fn ($l) => $l->account->name)->unique()->implode(', ') }}</td>
            <td>{{ $e->description }} @if($e->possible_duplicate)<span class="chip chip-amber">Possible duplicate</span>@endif @if($e->receipt_document_id)<span class="chip chip-gray">Receipt</span>@endif</td>
            <td class="num">@money($e->total_cents)</td>
            <td>{{ $e->kind === 'bill' ? 'Bill · ' : '' }}@include('partials.status', ['s' => $e->status])</td>
        </tr>
    @empty
        <tr><td colspan="6"><div class="empty m-4">No expenses yet.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
@endsection
