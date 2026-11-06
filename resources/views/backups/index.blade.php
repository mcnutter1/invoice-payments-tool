@extends('layouts.app')
@section('title', 'Backups')
@section('content')
@php
    $tz = $biz->timezone;
    $badge = ['created' => 'chip-green', 'updated' => 'chip-blue', 'deleted' => 'chip-red'];
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1>Backups</h1>
        <p class="text-sm text-slate-500">
            @if (! $configured) Backup storage hasn’t been set up by an administrator yet.
            @elseif (! $biz->backups_enabled) Backups are turned off for this business — ask an administrator to enable them.
            @else Every hour, changes are backed up; once a day, a full copy. Next run: {{ $nextType }}.@endif
        </p>
    </div>
    @if ($configured && $biz->backups_enabled)
        <form method="post" action="{{ $bu }}/backups/run">@csrf<button class="btn">Back up now</button></form>
    @endif
</div>

<form method="get" class="card card-body mt-4 flex flex-wrap items-end gap-2" role="search">
    <div class="flex-1"><label class="label" for="q">Find anything that was backed up</label>
        <input class="input" id="q" name="q" value="{{ $q }}" placeholder="Client name, invoice number, contract, vendor, file name…"></div>
    <div><label class="label" for="type">Kind</label>
        <select class="input" id="type" name="type"><option value="">All kinds</option>
            @foreach (\App\Services\Backups\BackupCatalog::ENTITIES as $k => $v)<option value="{{ $k }}" @selected($type === $k)>{{ $v }}</option>@endforeach
        </select></div>
    <button class="btn btn-primary">Search</button>
</form>

@if ($records !== null)
<div class="card mt-4"><div class="card-head"><h2>{{ $records->count() }} match{{ $records->count() === 1 ? '' : 'es' }}</h2></div>
    <div class="overflow-x-auto"><table class="tbl">
        <thead><tr><th>Record</th><th>Kind</th><th class="text-right">Versions</th><th>Last changed</th><th></th></tr></thead>
        <tbody>
        @forelse ($records as $r)
            <tr><td><a href="{{ $bu }}/backups/records/{{ $r->table_name }}/{{ $r->record_key }}" class="font-medium">{{ $r->label }}</a> @if($r->deleted)<span class="chip chip-red">deleted</span>@endif</td>
                <td class="text-xs">{{ \App\Services\Backups\BackupCatalog::title($r->table_name) }}</td>
                <td class="text-right">{{ $r->versions }}</td>
                <td class="text-xs">{{ $r->last_changed_at?->timezone($tz)->format('M j, Y g:ia') }}</td>
                <td class="text-right"><a class="btn btn-sm" href="{{ $bu }}/backups/records/{{ $r->table_name }}/{{ $r->record_key }}">History &amp; restore</a></td></tr>
        @empty
            <tr><td colspan="5" class="text-slate-500">Nothing matched in the backups.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endif

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="card xl:col-span-2"><div class="card-head"><h2>Recent changes across backups</h2></div>
        <ol class="card-body max-h-[36rem] space-y-1 overflow-y-auto text-sm">
        @forelse ($recent as $c)
            <li class="flex flex-wrap items-center gap-2">
                <span class="chip {{ $badge[$c->action] ?? 'chip-gray' }}">{{ $c->action }}</span>
                <a href="{{ $bu }}/backups/records/{{ $c->table_name }}/{{ $c->record_key }}" class="font-medium">{{ $c->label }}</a>
                <span class="text-xs text-slate-500">{{ \App\Services\Backups\BackupCatalog::title($c->table_name) }} · backup of {{ $c->snapshot?->started_at->timezone($tz)->format('M j, g:ia') }}</span>
            </li>
        @empty
            <li class="text-slate-500">No changes recorded yet.</li>
        @endforelse
        </ol>
    </div>
    <div class="card"><div class="card-head"><h2>Backup points</h2></div>
        <ul class="card-body space-y-2 text-sm">
        @forelse ($snapshots as $s)
            <li class="flex items-start justify-between gap-2">
                <span><a href="{{ $bu }}/backups/{{ $s->ulid }}" class="font-medium">{{ $s->started_at->timezone($tz)->format('M j, g:ia') }}</a>
                    <span class="chip {{ $s->type === 'full' ? 'chip-violet' : 'chip-gray' }}">{{ $s->type }}</span>
                    @if ($s->trigger !== 'schedule')<span class="chip chip-gray">{{ str_replace('_', ' ', $s->trigger) }}</span>@endif
                    <br><span class="text-xs text-slate-500">
                        @if ($s->status === 'completed'){{ $s->created_count }} new · {{ $s->updated_count }} changed · {{ $s->deleted_count }} deleted · {{ \App\Models\BackupSnapshot::humanBytes($s->bytes) }}
                        @else<span class="text-red-700">{{ $s->status }}{{ $s->error ? ': '.\Illuminate\Support\Str::limit($s->error, 80) : '' }}</span>@endif
                    </span></span>
            </li>
        @empty
            <li class="text-slate-500">No backups yet.</li>
        @endforelse
        </ul>
        <div class="card-body pt-0">{{ $snapshots->links() }}</div>
    </div>
</div>
@endsection
