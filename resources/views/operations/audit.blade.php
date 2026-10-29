@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
<h1>Audit log</h1>
<form method="get" class="mt-4 flex gap-2"><label for="action" class="sr-only">Action prefix</label><input class="input max-w-xs" id="action" name="action" value="{{ request('action') }}" placeholder="Filter by action, e.g. invoice. or payment."><button class="btn">Filter</button></form>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Object</th><th>Details</th></tr></thead><tbody>
    @forelse ($events as $e)
        <tr><td class="whitespace-nowrap">{{ $e->created_at->timezone($biz->timezone)->format('M j, Y g:i:sa') }}</td>
            <td>{{ $e->actor?->label() ?? ucfirst($e->actor_type) }}</td><td><code class="text-xs">{{ $e->action }}</code></td>
            <td class="text-xs">{{ $e->object_type }} {{ $e->object_id ? '#'.$e->object_id : '' }}</td>
            <td class="max-w-md text-xs text-slate-600">@if($e->reason)<div>Reason: {{ $e->reason }}</div>@endif
                @if($e->after)<div class="break-all">{{ \Illuminate\Support\Str::limit(json_encode($e->after, JSON_UNESCAPED_SLASHES), 240) }}</div>@endif
                <div class="text-slate-400">{{ $e->ip }} · {{ \Illuminate\Support\Str::limit($e->correlation_id, 18) }}</div></td></tr>
    @empty<tr><td colspan="5" class="text-slate-500">No events.</td></tr>@endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $events->links() }}</div>
<p class="hint mt-2">Audit events are append-only in the application. A host administrator with direct database access could still alter them; see docs/SIGNATURE_EVIDENCE.md.</p>
@endsection
