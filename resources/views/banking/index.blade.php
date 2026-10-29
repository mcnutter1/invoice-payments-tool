@extends('layouts.app')
@section('title', 'Banking')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Banking</h1>
    @if ($ctx->can('banking.manage'))<a href="{{ $bu }}/movements/new" class="btn">Transfer / owner / loan activity</a>@endif
</div>
<p class="mt-1 text-sm text-slate-500">Accounts are manual: import CSV statements, match rows to what you recorded, and reconcile to your statement. No automated bank feeds.</p>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($accounts as $a)
        <a href="{{ $bu }}/banking/{{ $a->id }}" class="metric">
            <div class="metric-label">{{ $a->chartAccount->subtype === 'credit_card' ? 'Credit card' : 'Bank' }} · {{ $a->institution }} {{ $a->last4 ? '••'.$a->last4 : '' }}</div>
            <div class="font-semibold text-slate-900">{{ $a->name }}</div>
            @php $bal = $balances[$a->chart_account_id] ?? 0; @endphp
            <div class="metric-value">@money($a->chartAccount->type === 'asset' ? $bal : -$bal)</div>
            <div class="metric-note">Ledger balance{{ ($unmatched[$a->id] ?? 0) ? ' · '.$unmatched[$a->id].' rows to review' : '' }}</div>
        </a>
    @endforeach
</div>
@if ($ctx->can('banking.manage'))
<details class="card mt-6"><summary class="card-head cursor-pointer"><h2>Add bank or credit card account</h2></summary>
    <form method="post" action="{{ $bu }}/banking" class="card-body grid gap-3 sm:grid-cols-3">@csrf
        <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" required></div>
        <div><label class="label" for="type">Type</label><select class="input" id="type" name="type"><option value="bank">Bank account</option><option value="credit_card">Credit card</option></select></div>
        <div><label class="label" for="institution">Institution</label><input class="input" id="institution" name="institution"></div>
        <div><label class="label" for="last4">Last 4</label><input class="input" id="last4" name="last4" inputmode="numeric" maxlength="4"></div>
        <div><label class="label" for="opening_balance">Opening balance ($)</label><input class="input" id="opening_balance" name="opening_balance" type="number" step="0.01"></div>
        <div><label class="label" for="opening_date">As of</label><input class="input" id="opening_date" name="opening_date" type="date"></div>
        <div><button class="btn btn-primary">Add account</button></div>
    </form>
</details>
@endif
@if ($movements->isNotEmpty())
<div class="card mt-6 overflow-x-auto"><div class="card-head"><h2>Recent transfers &amp; owner activity</h2></div>
    <table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>From</th><th>To</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach ($movements as $m)<tr><td>{{ $m->movement_date->format('M j, Y') }}</td><td>{{ \App\Models\MoneyMovement::KINDS[$m->kind] }}</td><td>{{ $m->fromAccount->name }}</td><td>{{ $m->toAccount->name }}</td><td class="num">@money($m->amount_cents)</td></tr>@endforeach
    </tbody></table></div>
@endif
@endsection
