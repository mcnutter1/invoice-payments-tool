@extends('layouts.app')
@section('title', 'Recurring billing')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Recurring billing</h1>
    @if ($ctx->can('recurring.manage'))<a href="{{ $bu }}/recurring/new" class="btn btn-primary">+ New schedule</a>@endif
</div>
<p class="mt-1 text-sm text-slate-500">Auto-send emails a request for payment. It never debits a client's bank account or Zelle.</p>
<div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
    <div class="metric"><div class="metric-label">Annual recurring revenue</div><div class="metric-value">@money($revenue['arr'])</div><div class="metric-note">Active schedules, annualized, before tax</div></div>
    <div class="metric"><div class="metric-label">Monthly recurring revenue</div><div class="metric-value">@money($revenue['mrr'])</div><div class="metric-note">ARR ÷ 12</div></div>
    <div class="metric col-span-2 lg:col-span-1"><div class="metric-label">Active schedules</div><div class="metric-value">{{ $revenue['count'] }}</div><div class="metric-note">{{ count($revenue['by_client']) }} client(s)</div></div>
</div>
@if ($review->isNotEmpty())
<div class="card mt-4 border-amber-300">
    <div class="card-head"><h2>Missed periods needing review</h2><span class="text-xs text-slate-500">Older periods missed while automation was down are never sent automatically.</span></div>
    <div class="overflow-x-auto"><table class="tbl"><tbody>
    @foreach ($review as $run)
        <tr><td>{{ $run->schedule->name }} · {{ $run->schedule->client->display_name }}</td><td>Period {{ $run->period_date->format('M j, Y') }}</td>
            <td class="text-right">@if ($ctx->can('recurring.manage'))
                <form method="post" action="{{ $bu }}/schedule-runs/{{ $run->id }}/generate" class="inline">@csrf<button class="btn btn-sm">Create draft</button></form>
                <form method="post" action="{{ $bu }}/schedule-runs/{{ $run->id }}/skip" class="inline-flex gap-1">@csrf<input class="input input-sm w-36" name="reason" placeholder="Reason" required aria-label="Skip reason"><button class="btn btn-sm btn-ghost">Skip</button></form>
            @endif</td></tr>
    @endforeach
    </tbody></table></div>
</div>
@endif
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Schedule</th><th>Client</th><th>Cadence</th><th class="num">Per period</th><th class="num">Annual value</th><th>Next run</th><th>Automation</th><th>Status</th></tr></thead><tbody>
    @forelse ($schedules as $s)
        <tr><td><a href="{{ $bu }}/recurring/{{ $s->id }}" class="font-medium">{{ $s->name }}</a>@if($s->contract)<div class="text-xs text-slate-500">{{ $s->contract->number }}</div>@endif</td>
            <td><a href="{{ $bu }}/clients/{{ $s->client_id }}">{{ $s->client->display_name }}</a></td>
            <td>Every {{ $s->interval > 1 ? $s->interval.' ' : '' }}{{ $s->cadence }}{{ $s->interval > 1 ? 's' : '' }}</td>
            <td class="num">@money($amounts[$s->id])</td>
            <td @class(['num', 'text-slate-400' => $s->status !== 'active'])>@money($revenue['by_schedule'][$s->id] ?? 0)</td>
            <td>{{ $s->next_run_date?->format('M j, Y') ?? '—' }}</td>
            <td class="text-xs">{{ $s->auto_issue ? ($s->auto_send ? 'Issue & email' : 'Issue only') : 'Draft for review' }}</td>
            <td>@include('partials.status', ['s' => $s->status])</td></tr>
    @empty
        <tr><td colspan="8"><div class="empty m-4">No recurring schedules.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
