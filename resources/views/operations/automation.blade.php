@extends('layouts.app')
@section('title', 'Automation monitor')
@section('content')
<h1>Automation monitor</h1>
@php
    $hb = fn ($n) => $heartbeats[$n] ?? null;
    $age = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->diffForHumans() : 'never';
    $sched = $hb('scheduler');
    $cronOk = $sched && \Illuminate\Support\Carbon::parse($sched->last_success_at)->gt(now()->subMinutes(5));
@endphp
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="metric"><div class="metric-label">Cron heartbeat</div><div class="mt-1"><span class="chip {{ $cronOk ? 'chip-green' : 'chip-red' }}">{{ $cronOk ? 'Running' : 'Stale' }}</span></div><div class="metric-note">Last: {{ $age($sched?->last_success_at) }}</div></div>
    <div class="metric"><div class="metric-label">Sign-in service</div><div class="mt-1"><span class="chip {{ $auth['status'] === 'healthy' ? 'chip-green' : ($auth['status'] === 'failed' ? 'chip-red' : 'chip-amber') }}">{{ ucfirst($auth['status']) }}</span></div><div class="metric-note">{{ $auth['message'] }}</div></div>
    <div class="metric"><div class="metric-label">Email service</div><div class="mt-1"><span class="chip {{ $notify['status'] === 'healthy' ? 'chip-green' : ($notify['status'] === 'failed' ? 'chip-red' : 'chip-amber') }}">{{ ucfirst($notify['status']) }}</span></div><div class="metric-note">{{ $notify['message'] }} · {{ $queued }} queued</div></div>
    <div class="metric"><div class="metric-label">Backups</div>
        @if ($backup)<div class="mt-1"><span class="chip {{ ($backup['ok'] ?? false) && \Illuminate\Support\Carbon::parse($backup['finished_at'] ?? '2000-01-01')->gt(now()->subHours(36)) ? 'chip-green' : 'chip-red' }}">{{ ($backup['ok'] ?? false) ? 'OK' : 'Failed' }}</span></div><div class="metric-note">Last: {{ $age($backup['finished_at'] ?? null) }}</div>
        @else<div class="mt-1"><span class="chip chip-amber">Unknown</span></div><div class="metric-note">No backup status file found</div>@endif
    </div>
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <div class="card overflow-x-auto"><div class="card-head"><h2>Jobs</h2></div>
        <table class="tbl"><thead><tr><th>Job</th><th>Last success</th><th>Last failure</th></tr></thead><tbody>
        @foreach (['outbox' => 'Email outbox', 'recurring' => 'Recurring billing', 'reminders' => 'Overdue reminders', 'maintenance' => 'Maintenance'] as $k => $label)
            @php $h = $hb($k); @endphp
            <tr><td>{{ $label }}</td><td>{{ $age($h?->last_success_at) }}</td><td>{{ $h?->last_failure_at ? $age($h->last_failure_at) : '—' }} @if($h?->last_error)<div class="text-xs text-red-700">{{ \Illuminate\Support\Str::limit($h->last_error, 160) }}</div>@endif</td></tr>
        @endforeach
        </tbody></table></div>
    <div class="card overflow-x-auto"><div class="card-head"><h2>Upcoming recurring runs</h2></div>
        <table class="tbl"><tbody>
        @forelse ($schedules as $s)<tr><td><a href="{{ $bu }}/recurring/{{ $s->id }}">{{ $s->name }}</a> · {{ $s->client->display_name }}</td><td>{{ $s->next_run_date?->format('M j, Y') }}</td><td class="text-xs">last run {{ $age($s->last_run_at) }}</td></tr>
        @empty<tr><td class="text-slate-500">No active schedules.</td></tr>@endforelse
        @foreach ($reviewRuns as $r)<tr><td colspan="3"><span class="chip chip-amber">Needs review</span> {{ $r->schedule->name }} period {{ $r->period_date->format('M j, Y') }}</td></tr>@endforeach
        @foreach ($failures as $f)<tr><td colspan="3" class="text-xs text-red-700">{{ $f->created_at->diffForHumans() }}: {{ $f->after['error'] ?? 'run failed' }}</td></tr>@endforeach
        </tbody></table></div>
</div>
@if ($needsReview->isNotEmpty())
<div class="card mt-4 border-red-200 overflow-x-auto"><div class="card-head"><h2>Emails needing review</h2></div>
    <table class="tbl"><tbody>
    @foreach ($needsReview as $o)
        <tr><td>{{ $o->kind }} → {{ $o->to_email }}</td><td>@include('partials.status', ['s' => $o->status])</td><td class="text-xs">{{ $o->last_error }}</td>
            <td class="text-right">@if ($o->invoice_id)<a href="{{ $bu }}/invoices/{{ $o->invoice_id }}">Open invoice</a>@endif
                @if ($o->encrypted_body && $ctx->can('invoices.send'))<form method="post" action="{{ $bu }}/outbox/{{ $o->id }}/resend" class="inline" data-confirm="{{ $o->status === 'delivery_unknown' ? 'This may already have been delivered. Resend anyway?' : 'Re-queue?' }}">@csrf<button class="btn btn-sm">Resend</button></form>@endif</td></tr>
    @endforeach
    </tbody></table></div>
@endif
<div class="card mt-4 overflow-x-auto"><div class="card-head"><h2>Recent emails</h2><span class="text-xs text-slate-500">Storage used: {{ number_format($storageBytes / 1048576, 1) }} MB</span></div>
    <table class="tbl"><thead><tr><th>Queued</th><th>Type</th><th>To</th><th>Status</th><th>Attempts</th></tr></thead><tbody>
    @forelse ($outbox as $o)<tr><td>{{ $o->created_at->timezone($biz->timezone)->format('M j, g:ia') }}</td><td>{{ $o->kind }}</td><td>{{ $o->to_email }}</td><td>@include('partials.status', ['s' => $o->status])</td><td>{{ $o->attempts }}</td></tr>
    @empty<tr><td colspan="5" class="text-slate-500">No emails yet.</td></tr>@endforelse
    </tbody></table></div>
@endsection
