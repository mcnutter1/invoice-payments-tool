@extends('layouts.app')
@section('title', 'Payments')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Payments</h1>
    @if ($ctx->can('payments.record'))<a href="{{ $bu }}/payments/new" class="btn btn-primary">+ Receive payment</a>@endif
</div>
<p class="mt-1 text-sm text-slate-500">Zelle and check payments happen outside this app. Record or confirm them here once you see the money in your bank or have the check in hand.</p>

<section id="claims" class="card mt-4">
    <div class="card-head"><h2>Client payment reports</h2><span class="text-xs text-slate-500">Not payments until you confirm them</span></div>
    <div class="overflow-x-auto">
    <table class="tbl"><thead><tr><th>Reported</th><th>Client · invoice</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse ($claims as $c)
        <tr class="{{ $c->created_at->lt(now()->subDays($biz->claim_review_days)) ? 'bg-amber-50' : '' }}">
            <td>{{ $c->created_at->timezone($biz->timezone)->format('M j, Y') }} @if($c->created_at->lt(now()->subDays($biz->claim_review_days)))<span class="chip chip-red">Stale</span>@endif</td>
            <td>{{ $c->invoice->client->display_name }} · {{ $c->invoice->number }}</td>
            <td>{{ ucfirst($c->method) }} {{ $c->check_number ? '#'.$c->check_number : '' }}</td>
            <td class="num">@money($c->reported_amount_cents)</td>
            <td>@include('partials.status', ['s' => $c->status])</td>
            <td class="text-right"><a class="btn btn-sm" href="{{ $bu }}/claims/{{ $c->id }}">Review</a></td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-slate-500">No reports waiting for review.</td></tr>
    @endforelse
    </tbody></table></div>
</section>

@if ($undeposited->isNotEmpty())
<section class="card mt-4">
    <div class="card-head"><h2>Undeposited checks &amp; cash</h2></div>
    <div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Received</th><th>Client</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead><tbody>
    @foreach ($undeposited as $p)
        <tr><td>{{ $p->received_date->format('M j, Y') }}</td><td>{{ $p->client->display_name }}</td><td>{{ ucfirst($p->method) }} {{ $p->check_number ? '#'.$p->check_number : '' }}</td><td class="num">@money($p->amount_cents)</td>
            <td class="text-right">@if($ctx->can('payments.record'))
                <form method="post" action="{{ $bu }}/payments/{{ $p->id }}/deposit" class="inline-flex gap-1">@csrf
                    <select name="bank_account_id" class="input input-sm w-auto" aria-label="Bank account">@foreach ($banks as $bk)<option value="{{ $bk->id }}">{{ $bk->name }}</option>@endforeach</select>
                    <input type="date" name="date" class="input input-sm w-auto" value="{{ $biz->localToday()->toDateString() }}" aria-label="Deposit date">
                    <button class="btn btn-sm">Mark deposited</button></form>@endif</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endif

<form method="get" class="mt-6 flex flex-wrap gap-2">
    <select name="status" class="input w-auto" aria-label="Status"><option value="">All statuses</option>@foreach (['recorded', 'deposited', 'cleared', 'reversed'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select name="method" class="input w-auto" aria-label="Method"><option value="">All methods</option>@foreach (\App\Services\Payments\PaymentService::METHODS as $k => $v)<option value="{{ $k }}" @selected(request('method') === $k)>{{ $v }}</option>@endforeach</select>
    <button class="btn">Filter</button>
</form>
<div class="card mt-3 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Client</th><th>Method</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    @forelse ($payments as $p)
        <tr><td><a href="{{ $bu }}/payments/{{ $p->id }}">{{ $p->received_date->format('M j, Y') }}</a></td><td>{{ $p->client->display_name }}</td>
            <td>{{ \App\Services\Payments\PaymentService::METHODS[$p->method] ?? $p->method }} {{ $p->check_number ? '#'.$p->check_number : '' }}</td>
            <td class="num">@money($p->amount_cents)</td><td>@include('partials.status', ['s' => $p->status])</td></tr>
    @empty
        <tr><td colspan="5"><div class="empty m-4">No payments recorded yet.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $payments->links() }}</div>
@endsection
