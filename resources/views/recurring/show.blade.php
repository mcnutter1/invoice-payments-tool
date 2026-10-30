@extends('layouts.app')
@section('title', $schedule->name)
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $schedule->name }} @include('partials.status', ['s' => $schedule->status])</h1>
        <p class="text-sm text-slate-500">{{ $schedule->client->display_name }} · every {{ $schedule->interval > 1 ? $schedule->interval.' ' : '' }}{{ $schedule->cadence }}{{ $schedule->interval > 1 ? 's' : '' }} · @money($amount) per period · @money($annual)/year
            @if ($schedule->contract) · <a href="{{ $bu }}/contracts/{{ $schedule->contract_id }}">{{ $schedule->contract->number }}</a> ({{ $schedule->contract->status }}) @endif</p>
    </div>
    @if ($ctx->can('recurring.manage') && $schedule->status !== 'cancelled')
    <div class="flex flex-wrap gap-2">
        <a class="btn" href="{{ $bu }}/recurring/{{ $schedule->id }}/edit">Edit</a>
        @if ($schedule->status === 'active')
            <form method="post" action="{{ $bu }}/recurring/{{ $schedule->id }}/status">@csrf<input type="hidden" name="status" value="paused"><button class="btn">Pause</button></form>
        @elseif (in_array($schedule->status, ['paused', 'ended']))
            <form method="post" action="{{ $bu }}/recurring/{{ $schedule->id }}/status" data-confirm="Resume from the next period on or after today? Periods missed while paused are not billed.">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-primary">Resume</button></form>
        @endif
        <form method="post" action="{{ $bu }}/recurring/{{ $schedule->id }}/status" data-confirm="Cancel this schedule permanently?">@csrf<input type="hidden" name="status" value="cancelled"><button class="btn btn-danger">Cancel</button></form>
    </div>
    @endif
</div>
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="card card-body">
            <h2>Next three occurrences</h2>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse ($upcoming as $d)<li>{{ $d->format('l, M j, Y') }} — {{ $schedule->auto_issue ? ($schedule->auto_send ? 'issued & emailed' : 'issued, not emailed') : 'draft created for review' }}</li>
                @empty<li class="text-slate-500">No upcoming occurrences.</li>@endforelse
            </ul>
            <p class="hint mt-2">Runs once per period using {{ $schedule->timezone }} dates. Duplicate generation for a period is blocked by a unique key.</p>
        </div>
        <div class="card overflow-x-auto"><div class="card-head"><h2>History</h2></div>
            <table class="tbl"><thead><tr><th>Period</th><th>Result</th><th>Invoice</th></tr></thead><tbody>
            @forelse ($schedule->runs as $run)
                <tr><td>{{ $run->period_date->format('M j, Y') }}</td><td>@include('partials.status', ['s' => $run->status]) <span class="text-xs text-slate-500">{{ $run->error }}</span></td>
                    <td>@if($run->invoice)<a href="{{ $bu }}/invoices/{{ $run->invoice_id }}">{{ $run->invoice->displayNumber() }}</a>@endif</td></tr>
            @empty<tr><td colspan="3" class="text-slate-500">No runs yet.</td></tr>@endforelse
            </tbody></table></div>
    </div>
    <aside class="space-y-4">
        <div class="card"><div class="card-head"><h2>Lines</h2></div><ul class="card-body space-y-1 text-sm">
            @foreach ($schedule->lines as $l)<li>{{ $l->name }} · {{ rtrim(rtrim((string) $l->quantity, '0'), '.') }} × ${{ number_format((float) $l->unit_price, 2) }}</li>@endforeach
        </ul></div>
        <div class="card"><div class="card-head"><h2>Activity</h2></div><ul class="card-body space-y-1 text-xs">
            @forelse ($activity as $a)<li>{{ $a->created_at->timezone($biz->timezone)->format('M j, g:ia') }} · {{ $a->action }} · {{ $a->actor?->label() ?? $a->actor_type }} @if($a->reason)— {{ $a->reason }}@endif</li>@empty<li class="text-slate-500">No activity.</li>@endforelse
        </ul></div>
    </aside>
</div>
@endsection
