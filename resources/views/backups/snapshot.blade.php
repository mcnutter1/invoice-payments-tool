@extends('layouts.app')
@section('title', 'Backup point')
@section('content')
@php $tz = $biz->timezone; $badge = ['created' => 'chip-green', 'updated' => 'chip-blue', 'deleted' => 'chip-red']; @endphp
<p class="text-sm"><a href="{{ $bu }}/backups">← Backups</a></p>
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <h1>Backup of {{ $snap->started_at->timezone($tz)->format('M j, Y g:i:sa') }}
        <span class="chip {{ $snap->type === 'full' ? 'chip-violet' : 'chip-gray' }}">{{ $snap->type }}</span>
        <span class="chip {{ $snap->status === 'completed' ? 'chip-green' : 'chip-red' }}">{{ $snap->status }}</span></h1>
    @if ($snap->isComplete())<a class="btn" href="{{ $bu }}/backups/{{ $snap->ulid }}/export">Download export (JSON)</a>@endif
</div>
<div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="metric"><div class="metric-label">Records in business</div><div class="metric-value">{{ number_format($snap->row_count) }}</div></div>
    <div class="metric"><div class="metric-label">Changes</div><div class="metric-value">{{ $snap->changeCount() }}</div><div class="metric-note">{{ $snap->created_count }} new · {{ $snap->updated_count }} changed · {{ $snap->deleted_count }} deleted</div></div>
    <div class="metric"><div class="metric-label">Size</div><div class="metric-value">{{ \App\Models\BackupSnapshot::humanBytes($snap->bytes) }}</div><div class="metric-note">{{ $snap->files_uploaded }} new file(s)</div></div>
    <div class="metric"><div class="metric-label">Restores from</div><div class="metric-value text-lg">{{ $chain->count() }} object(s)</div><div class="metric-note">{{ $snap->type === 'full' ? 'Self-contained full copy' : 'Daily full + '.($chain->count() - 1).' hourly delta(s)' }}</div></div>
</div>
@if ($snap->error)<div class="alert alert-warn mt-4">{{ $snap->error }}</div>@endif

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="card xl:col-span-2"><div class="card-head"><h2>What changed in this backup</h2></div>
        <div class="overflow-x-auto"><table class="tbl">
            <thead><tr><th>Change</th><th>Record</th><th>Kind</th><th></th></tr></thead>
            <tbody>
            @forelse ($changes as $c)
                <tr><td><span class="chip {{ $badge[$c->action] ?? 'chip-gray' }}">{{ $c->action }}</span></td>
                    <td class="font-medium">{{ $c->label }}</td>
                    <td class="text-xs">{{ \App\Services\Backups\BackupCatalog::title($c->table_name) }}</td>
                    <td class="text-right"><a class="btn btn-sm" href="{{ $bu }}/backups/records/{{ $c->table_name }}/{{ $c->record_key }}?v={{ $snap->ulid }}">Preview</a></td></tr>
            @empty
                <tr><td colspan="4" class="text-slate-500">No changes since the previous backup.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="card-body">{{ $changes->links() }}</div>
    </div>
    <aside class="space-y-4">
        @if ($snap->table_stats)
        <div class="card"><div class="card-head"><h2>Contents</h2></div>
            <ul class="card-body max-h-80 space-y-0.5 overflow-y-auto text-xs">
                @foreach ($snap->table_stats as $t => $st)<li class="flex justify-between"><span>{{ \App\Services\Backups\BackupCatalog::title($t) }}</span><span class="text-slate-500">{{ $st['rows'] }}@if($st['changed']) · {{ $st['changed'] }} changed @endif</span></li>@endforeach
            </ul></div>
        @endif
        @if ($snap->isComplete())
        <form method="post" action="{{ $bu }}/backups/{{ $snap->ulid }}/rollback" class="card card-body space-y-2 border-red-200" data-confirm="Restore the entire business to this backup point? Changes made after it will be replaced.">@csrf
            <h2 class="text-base">Restore the whole business to this point</h2>
            <p class="text-xs text-slate-600">Replaces all clients, invoices, contracts, payments, accounting and settings with this backup. A full backup of the current data is taken first, so this can be undone. Audit logs, email history and signing evidence are kept.</p>
            <label class="label" for="confirm">Type <strong>{{ $biz->name() }}</strong> to confirm</label>
            <input class="input" id="confirm" name="confirm" autocomplete="off" required>
            <button class="btn btn-danger">Restore business</button>
        </form>
        @endif
    </aside>
</div>
@endsection
