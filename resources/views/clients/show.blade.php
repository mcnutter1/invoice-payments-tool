@extends('layouts.app')
@section('title', $client->display_name)
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $client->display_name }}</h1>
        <p class="text-sm text-slate-500">{{ $client->legal_name }} @if($client->tax_exempt)<span class="chip chip-violet ml-2">Tax exempt</span>@endif @include('partials.status', ['s' => $client->status])</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($ctx->can('invoices.manage'))<a class="btn btn-primary" href="{{ $bu }}/invoices/new?client={{ $client->id }}">New invoice</a>@endif
        @if ($ctx->can('payments.record'))<a class="btn" href="{{ $bu }}/payments/new?client={{ $client->id }}">Receive payment</a>@endif
        @if ($ctx->can('contracts.manage'))<a class="btn" href="{{ $bu }}/contracts/new?client={{ $client->id }}">New contract</a>@endif
        @if ($ctx->can('invoices.view'))<a class="btn" href="{{ $bu }}/clients/{{ $client->id }}/statement">Statement</a>@endif
        @if ($ctx->can('clients.manage'))
            <a class="btn" href="{{ $bu }}/clients/{{ $client->id }}/edit">Edit</a>
            <form method="post" action="{{ $bu }}/clients/{{ $client->id }}/archive">@csrf<button class="btn btn-ghost">{{ $client->status === 'archived' ? 'Restore' : 'Archive' }}</button></form>
        @endif
    </div>
</div>
@if ($revenue || $values)
<div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @if ($revenue)
    <div class="metric"><div class="metric-label">Annual recurring revenue</div><div class="metric-value">@money($revenue['arr'])</div><div class="metric-note">@money($revenue['mrr'])/mo · {{ $revenue['count'] }} active plan(s)</div></div>
    @endif
    @if ($values)
    <div class="metric"><div class="metric-label">Lifetime billed</div><div class="metric-value">@money($values['billed'])</div><div class="metric-note">Issued invoices, incl. tax</div></div>
    @if ($values['collected'] !== null)<div class="metric"><div class="metric-label">Collected</div><div class="metric-value">@money($values['collected'])</div><div class="metric-note">Payments received</div></div>@endif
    <div class="metric"><div class="metric-label">Open balance</div><div class="metric-value">@money($balance)</div><div @class(['metric-note', 'font-medium text-red-700' => $values['overdue'] > 0])>{{ $values['overdue'] > 0 ? \App\Support\Money::format($values['overdue']).' overdue' : 'Nothing overdue' }}</div></div>
    @endif
</div>
@endif
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4">
        <div class="card card-body">
            <dl class="dl">
                @if (! $values)<dt>Open balance</dt><dd class="font-semibold">@money($balance)</dd>@endif
                @if ($credit > 0)<dt>Unapplied credit</dt><dd>@money($credit)</dd>@endif
                <dt>Email</dt><dd>{{ $client->email ?: '—' }}</dd>
                <dt>Phone</dt><dd>{{ $client->phone ?: '—' }}</dd>
                <dt>Billing</dt><dd>{!! nl2br(e(implode("\n", $client->billingAddressLines()) ?: '—')) !!}</dd>
                <dt>Terms</dt><dd>Net {{ $client->default_terms_days ?? $biz->default_terms_days }}</dd>
            </dl>
            @if ($client->notes)<p class="mt-3 rounded bg-amber-50 p-2 text-xs text-amber-900"><strong>Private note:</strong> {{ $client->notes }}</p>@endif
        </div>
        <div class="card">
            <div class="card-head"><h2>Contacts</h2></div>
            <div class="card-body space-y-2 text-sm">
                @forelse ($client->contacts as $ct)
                    <div class="border-b border-slate-100 pb-2 last:border-0">
                        <div class="flex items-start justify-between gap-2">
                            <div><div class="font-medium">{{ $ct->name }} @if($ct->title)<span class="text-slate-500">· {{ $ct->title }}</span>@endif</div>
                                <div class="text-slate-500">{{ $ct->email }} {{ $ct->phone }}</div>
                                <div>@if($ct->is_primary_billing)<span class="chip chip-blue">Billing</span>@endif @if($ct->is_signer)<span class="chip chip-violet">Signer</span>@endif</div></div>
                        </div>
                        @if ($ctx->can('clients.manage'))
                        <details class="mt-1" @if($errors->any() && old('contact_id') == $ct->id) open @endif>
                            <summary class="cursor-pointer text-xs text-brand-700">Edit or remove</summary>
                            <form method="post" action="{{ $bu }}/clients/{{ $client->id }}/contacts/{{ $ct->id }}" class="mt-2 space-y-2">
                                @csrf
                                <input type="hidden" name="contact_id" value="{{ $ct->id }}">
                                @include('clients._contact-fields', ['ct' => $ct])
                                <button class="btn btn-sm btn-primary">Save contact</button>
                            </form>
                            <form method="post" action="{{ $bu }}/clients/{{ $client->id }}/contacts/{{ $ct->id }}/delete" class="mt-2" data-confirm="Remove {{ $ct->name }} from this client?">@csrf<button class="btn btn-sm btn-danger">Remove contact</button></form>
                        </details>
                        @endif
                    </div>
                @empty
                    <p class="text-slate-500">No contacts.</p>
                @endforelse
                @if ($ctx->can('clients.manage'))
                <details class="mt-2"><summary class="cursor-pointer text-sm text-brand-700">+ Add contact</summary>
                    <form method="post" action="{{ $bu }}/clients/{{ $client->id }}/contacts" class="mt-2 space-y-2">
                        @csrf
                        @include('clients._contact-fields')
                        <button class="btn btn-sm btn-primary">Add</button>
                    </form>
                </details>
                @endif
            </div>
        </div>
    </div>
    <div class="space-y-4 lg:col-span-2">
        @if ($ctx->can('invoices.view'))
        <div class="card overflow-x-auto"><div class="card-head"><h2>Invoices</h2></div>
            <table class="tbl"><thead><tr><th>Number</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
            @forelse ($invoices as $i)
                <tr><td><a href="{{ $bu }}/invoices/{{ $i->id }}">{{ $i->displayNumber() }}</a></td><td>{{ $i->issue_date?->format('M j, Y') }}</td><td>{{ $i->due_date?->format('M j, Y') }}</td>
                    <td class="num">@money($i->total_cents)</td><td class="num">@money($i->balance_cents)</td><td>@include('partials.invoice-status', ['i' => $i])</td></tr>
            @empty <tr><td colspan="6" class="text-slate-500">No invoices.</td></tr> @endforelse
            </tbody></table></div>
        @endif
        @if ($ctx->can('payments.view'))
        <div class="card overflow-x-auto"><div class="card-head"><h2>Payments</h2></div>
            <table class="tbl"><thead><tr><th>Date</th><th>Method</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
            @forelse ($payments as $p)
                <tr><td><a href="{{ $bu }}/payments/{{ $p->id }}">{{ $p->received_date->format('M j, Y') }}</a></td><td>{{ ucfirst($p->method) }} {{ $p->check_number ? '#'.$p->check_number : '' }}</td><td class="num">@money($p->amount_cents)</td><td>@include('partials.status', ['s' => $p->status])</td></tr>
            @empty <tr><td colspan="4" class="text-slate-500">No payments.</td></tr> @endforelse
            </tbody></table></div>
        @endif
        @if ($ctx->can('contracts.view') && $contracts->isNotEmpty())
        <div class="card"><div class="card-head"><h2>Contracts</h2></div><div class="card-body text-sm space-y-1">
            @foreach ($contracts as $c)<div class="flex justify-between"><a href="{{ $bu }}/contracts/{{ $c->id }}">{{ $c->number }} · {{ $c->title }}</a>@include('partials.status', ['s' => $c->status])</div>@endforeach
        </div></div>
        @endif
        @if ($ctx->can('recurring.view') && $schedules->isNotEmpty())
        <div class="card overflow-x-auto"><div class="card-head"><h2>Recurring plans</h2>@if($ctx->can('recurring.manage'))<a class="btn btn-sm" href="{{ $bu }}/recurring/new?client={{ $client->id }}">+ New plan</a>@endif</div>
            <table class="tbl"><thead><tr><th>Plan</th><th>Next invoice</th><th class="num">Annual value</th><th>Status</th></tr></thead><tbody>
            @foreach ($schedules as $s)
                <tr><td><a href="{{ $bu }}/recurring/{{ $s->id }}">{{ $s->name }}</a><div class="text-xs text-slate-500">Every {{ $s->interval > 1 ? $s->interval.' ' : '' }}{{ $s->cadence }}{{ $s->interval > 1 ? 's' : '' }}</div></td>
                    <td>{{ $s->next_run_date?->format('M j, Y') ?? '—' }}</td>
                    <td @class(['num', 'text-slate-400' => $s->status !== 'active'])>@money($revenue['by_schedule'][$s->id] ?? 0)</td>
                    <td>@include('partials.status', ['s' => $s->status])</td></tr>
            @endforeach
            </tbody></table></div>
        @endif
        @if ($activity->isNotEmpty())
        <div class="card"><div class="card-head"><h2>Activity</h2></div><ul class="card-body space-y-1 text-sm">
            @foreach ($activity as $a)<li><span class="text-slate-500">{{ $a->created_at->timezone($biz->timezone)->format('M j, Y g:ia') }}</span> · {{ $a->action }}</li>@endforeach
        </ul></div>
        @endif
    </div>
</div>
@endsection
