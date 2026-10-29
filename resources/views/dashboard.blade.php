@extends('layouts.app')
@section('title', 'Overview')
@section('content')
@php
    $basisLabel = $metrics ? ucfirst($metrics['basis']).' basis' : '';
    $range = \Illuminate\Support\Carbon::parse($from)->format('M j, Y').' – '.\Illuminate\Support\Carbon::parse($to)->format('M j, Y');
@endphp
<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1>{{ $biz->name() }}</h1>
        <p class="text-sm text-slate-500">{{ $range }} @if($metrics) · {{ $basisLabel }} @endif · USD</p>
    </div>
    <form method="get" class="flex items-center gap-2">
        <label for="range" class="sr-only">Date range</label>
        <select id="range" name="range" class="input input-sm" data-autosubmit>
            @foreach (['ytd' => 'Fiscal year to date', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_year' => 'Last year'] as $k => $v)
                <option value="{{ $k }}" @selected($preset === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <noscript><button class="btn btn-sm">Apply</button></noscript>
    </form>
</div>

@if ($metrics)
<div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <a class="metric" href="{{ $bu }}/payments?range={{ $preset }}"><div class="metric-label">Collected</div><div class="metric-value">@money($metrics['collected'])</div><div class="metric-note">Confirmed payments received in range</div></a>
    <a class="metric" href="{{ $bu }}/reports/invoice-summary?range={{ $preset }}"><div class="metric-label">Invoiced</div><div class="metric-value">@money($metrics['issued'])</div><div class="metric-note">Issued invoices dated in range (incl. tax)</div></a>
    <a class="metric" href="{{ $bu }}/reports/profit-and-loss?range={{ $preset }}&basis={{ $metrics['basis'] }}"><div class="metric-label">Expenses</div><div class="metric-value">@money($metrics['expenses'])</div><div class="metric-note">{{ $basisLabel }} P&amp;L expenses</div></a>
    <a class="metric" href="{{ $bu }}/reports/profit-and-loss?range={{ $preset }}&basis={{ $metrics['basis'] }}"><div class="metric-label">Net profit</div><div class="metric-value {{ $metrics['net'] < 0 ? 'text-red-700' : '' }}">@money($metrics['net'])</div><div class="metric-note">{{ $basisLabel }} · income @money($metrics['income'])</div></a>
</div>
@endif
@if ($ar)
<div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <a class="metric" href="{{ $bu }}/invoices?status=open"><div class="metric-label">Outstanding A/R</div><div class="metric-value">@money($ar['outstanding'])</div><div class="metric-note">Open balances as of today</div></a>
    <a class="metric" href="{{ $bu }}/invoices?status=overdue"><div class="metric-label">Overdue</div><div class="metric-value {{ $ar['overdue'] > 0 ? 'text-red-700' : '' }}">@money($ar['overdue'])</div><div class="metric-note">{{ $ar['overdue_count'] }} invoice(s) past due</div></a>
    @if ($ctx->can('payments.view'))
    <a class="metric" href="{{ $bu }}/payments#claims"><div class="metric-label">Payment reports to review</div><div class="metric-value">{{ $claims->count() }}</div><div class="metric-note">Client-reported, not yet confirmed</div></a>
    @endif
    @if ($ctx->can('contracts.view'))
    <a class="metric" href="{{ $bu }}/contracts?status=sent"><div class="metric-label">Awaiting signature</div><div class="metric-value">{{ $awaitingSignature->count() }}</div><div class="metric-note">Sent or partially signed agreements</div></a>
    @endif
</div>
@endif

@if ($deliveryIssues || $reviewRuns)
<div class="alert alert-warn mt-4">
    @if ($deliveryIssues) {{ $deliveryIssues }} email(s) failed or have unknown delivery. <a href="{{ $bu }}/automation">Review</a>. @endif
    @if ($reviewRuns) {{ $reviewRuns }} missed recurring period(s) need review. <a href="{{ $bu }}/recurring">Review</a>. @endif
</div>
@endif

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    @if ($ctx->can('payments.view'))
    <section class="card">
        <div class="card-head"><h2>Payment reports</h2><a href="{{ $bu }}/payments" class="text-sm">All payments</a></div>
        <div class="card-body">
            @forelse ($claims as $c)
                <a href="{{ $bu }}/claims/{{ $c->id }}" class="flex items-center justify-between border-b border-slate-100 py-2 text-sm no-underline last:border-0">
                    <span>{{ $c->invoice->client->display_name }} · {{ $c->invoice->number }} · {{ ucfirst($c->method) }}</span>
                    <span class="num">@money($c->reported_amount_cents) <span class="chip chip-amber ml-2">Awaiting confirmation</span></span>
                </a>
            @empty
                <p class="text-sm text-slate-500">No client payment reports waiting.</p>
            @endforelse
        </div>
    </section>
    @endif
    @if ($ctx->can('recurring.view'))
    <section class="card">
        <div class="card-head"><h2>Recurring billing due soon</h2><a href="{{ $bu }}/recurring" class="text-sm">All schedules</a></div>
        <div class="card-body">
            @forelse ($dueSoon as $s)
                <a href="{{ $bu }}/recurring/{{ $s->id }}" class="flex justify-between border-b border-slate-100 py-2 text-sm no-underline last:border-0">
                    <span>{{ $s->name }} · {{ $s->client->display_name }}</span><span>{{ $s->next_run_date?->format('M j') }}</span>
                </a>
            @empty
                <p class="text-sm text-slate-500">Nothing due in the next 14 days.</p>
            @endforelse
        </div>
    </section>
    @endif
    @if ($ctx->can('contracts.view'))
    <section class="card">
        <div class="card-head"><h2>Contracts awaiting signatures</h2><a href="{{ $bu }}/contracts" class="text-sm">All contracts</a></div>
        <div class="card-body">
            @forelse ($awaitingSignature as $c)
                <a href="{{ $bu }}/contracts/{{ $c->id }}" class="flex justify-between border-b border-slate-100 py-2 text-sm no-underline last:border-0">
                    <span>{{ $c->number }} · {{ $c->title }} · {{ $c->client->display_name }}</span>@include('partials.status', ['s' => $c->status])
                </a>
            @empty
                <p class="text-sm text-slate-500">No agreements waiting.</p>
            @endforelse
        </div>
    </section>
    @endif
    @if ($ctx->can('invoices.view'))
    <section class="card">
        <div class="card-head"><h2>Recent invoices</h2><a href="{{ $bu }}/invoices" class="text-sm">All invoices</a></div>
        <div class="card-body">
            @forelse ($recentInvoices as $i)
                <a href="{{ $bu }}/invoices/{{ $i->id }}" class="flex justify-between border-b border-slate-100 py-2 text-sm no-underline last:border-0">
                    <span>{{ $i->displayNumber() }} · {{ $i->client->display_name }}</span>
                    <span class="num">@money($i->total_cents) @include('partials.invoice-status', ['i' => $i])</span>
                </a>
            @empty
                <div class="text-sm text-slate-500">No invoices yet. @if($ctx->can('invoices.manage'))<a href="{{ $bu }}/invoices/new">Create one</a>.@endif</div>
            @endforelse
        </div>
    </section>
    @endif
</div>
@endsection
