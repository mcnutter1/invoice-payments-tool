@extends('layouts.app')
@section('title', 'Backup history · '.$label)
@section('content')
@php
    $tz = $biz->timezone;
    $badge = ['created' => 'chip-green', 'updated' => 'chip-blue', 'deleted' => 'chip-red'];
    $show = fn ($v) => $v === null ? '—' : (is_bool($v) ? ($v ? 'true' : 'false') : \Illuminate\Support\Str::limit((string) $v, 300));
    $hidden = ['otp_hash', 'token_hash', 'tracking_hash', 'encrypted_body', 'tax_id_encrypted', 'signature_hash'];
    $mask = fn ($col, $v) => in_array($col, $hidden, true) && $v !== null ? '[hidden]' : $v;
    $self = '?v='.($selected?->snapshot->ulid ?? '');
@endphp
<p class="text-sm"><a href="{{ $bu }}/backups">← Backups</a></p>
<div class="mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1>{{ $label }}</h1>
        <p class="text-sm text-slate-500">{{ \App\Services\Backups\BackupCatalog::title($table) }} · {{ $changes->count() }} version(s) in backups ·
            @if ($current) currently exists @else <span class="text-red-700">no longer exists</span>@endif</p>
    </div>
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="card"><div class="card-head"><h2>Versions</h2></div>
        <ol class="card-body space-y-2 text-sm">
        @foreach ($changes as $c)
            @php $isSel = $selected && $selected->id === $c->id; @endphp
            <li @class(['rounded-md p-2', 'bg-brand-50' => $isSel])>
                <span class="chip {{ $badge[$c->action] ?? 'chip-gray' }}">{{ $c->action }}</span>
                @if ($c->action !== 'deleted')
                    <a href="?v={{ $c->snapshot->ulid }}" class="font-medium">{{ $c->snapshot->started_at->timezone($tz)->format('M j, Y g:ia') }}</a>
                @else
                    <span class="font-medium">{{ $c->snapshot->started_at->timezone($tz)->format('M j, Y g:ia') }}</span>
                @endif
                <br><span class="text-xs text-slate-500">{{ $c->label }} · {{ $c->snapshot->type }} backup</span>
            </li>
        @endforeach
        </ol>
    </div>

    <div class="space-y-4 xl:col-span-2">
        @if ($error)<div class="alert alert-warn">{{ $error }}</div>@endif
        @if ($version)
        <div class="card"><div class="card-head"><h2>Preview · {{ $selected->snapshot->started_at->timezone($tz)->format('M j, Y g:ia') }}</h2>
            @if ($differCount || $changedCount)
                <a class="btn btn-sm" href="{{ $self }}{{ $diffOnly ? '' : '&diff=1' }}">{{ $diffOnly ? 'Show all fields' : 'Show only differences' }}</a>
            @endif</div>
            <div class="card-body flex flex-wrap gap-2 border-b border-slate-100 text-sm">
                @if ($current === null)
                    <span class="chip chip-red">Deleted since — restoring brings it back</span>
                @elseif ($differCount)
                    <span class="chip chip-amber">{{ $differCount }} field{{ $differCount === 1 ? '' : 's' }} differ{{ $differCount === 1 ? 's' : '' }} from now</span>
                @else
                    <span class="chip chip-green">Identical to the current record</span>
                @endif
                @if ($previous !== null)
                    @if ($changedCount)
                        <span class="chip chip-blue">{{ $changedCount }} field{{ $changedCount === 1 ? '' : 's' }} changed in this version</span>
                    @else
                        <span class="chip chip-gray">No content changes vs. the previous version (only timestamps)</span>
                    @endif
                @elseif ($selected->action === 'created')
                    <span class="chip chip-gray">First backed-up version</span>
                @endif
            </div>
            @if ($files)
            <div class="card-body space-y-3 border-b border-slate-100">
                @foreach ($files as $f)
                    <div class="text-sm"><a href="{{ $bu }}/backups/files/{{ $f->document_id }}" target="_blank" rel="noopener" class="font-medium">{{ $f->original_name ?: 'File #'.$f->document_id }}</a>
                        <span class="text-xs text-slate-500">{{ $f->mime }} · {{ \App\Models\BackupSnapshot::humanBytes($f->size) }}</span></div>
                    @if (str_starts_with((string) $f->mime, 'image/'))
                        <img src="{{ $bu }}/backups/files/{{ $f->document_id }}" alt="{{ $f->original_name }}" class="max-h-56 rounded border border-slate-200">
                    @elseif ($f->mime === 'application/pdf' && $loop->first)
                        <iframe src="{{ $bu }}/backups/files/{{ $f->document_id }}" title="Preview of {{ $f->original_name }}" class="h-96 w-full rounded border border-slate-200"></iframe>
                    @endif
                @endforeach
            </div>
            @endif
            <div class="overflow-x-auto"><table class="tbl text-xs">
                <thead><tr><th>Field</th><th>In this backup</th><th>Now</th></tr></thead>
                <tbody>
                @forelse ($fields as $f)
                    @php $v = $mask($f['name'], $f['value']); $now = $mask($f['name'], $f['now']); @endphp
                    <tr @class(['bg-amber-50' => $f['differs'], 'text-slate-400' => $f['volatile']])>
                        <td class="whitespace-nowrap align-top">
                            <span @class(['font-semibold text-slate-900' => $f['differs'] || $f['changed'], 'font-medium' => ! $f['differs'] && ! $f['changed']])>{{ $f['name'] }}</span>
                            @if ($f['changed'])<br><span class="chip chip-blue">changed here</span>@endif
                            @if ($f['differs'])<br><span class="chip chip-amber">differs now</span>@endif
                        </td>
                        <td class="break-all align-top">
                            @if ($f['changed'])<div class="text-slate-400 line-through">{{ $show($mask($f['name'], $f['was'])) }}</div>@endif
                            <span @class(['font-semibold text-blue-800' => $f['changed']])>{{ $show($v) }}</span>
                        </td>
                        <td @class(['break-all align-top', 'font-semibold text-amber-800' => $f['differs'], 'text-slate-400' => $current === null])>{{ $current === null ? 'deleted' : $show($now) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-slate-500">No differences.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            <p class="card-body pt-2 hint">Timestamps and internal counters (greyed out) are ignored when comparing. Numbers, empty values and JSON are compared by meaning, not formatting.</p>
        </div>

        <form method="post" action="{{ $bu }}/backups/{{ $selected->snapshot->ulid }}/records/{{ $table }}/{{ $key }}/restore" class="card card-body space-y-3" data-confirm="Restore this version?">@csrf
            <h2 class="text-base">Restore this version</h2>
            <fieldset class="space-y-1 text-sm">
                <legend class="sr-only">How to restore</legend>
                @foreach (\App\Services\Backups\BackupRestorer::MODES as $mode => $text)
                    @php $enabled = match ($mode) { 'copy' => $copyable, 'missing' => $current === null, default => true }; @endphp
                    <label @class(['flex items-center gap-2', 'text-slate-400' => ! $enabled])>
                        <input type="radio" name="mode" value="{{ $mode }}" @disabled(! $enabled) @checked($current === null ? $mode === 'missing' : $mode === 'overwrite')> {{ $text }}
                        @if (! $enabled)<span class="text-xs">({{ $mode === 'copy' ? 'not available for this kind of record' : 'it still exists' }})</span>@endif
                    </label>
                @endforeach
            </fieldset>
            @if ($children)
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="children" value="0"><input class="check" type="checkbox" name="children" value="1" checked> Include its {{ implode(', ', array_map('strtolower', $children)) }} from the same backup</label>
            @endif
            @if (in_array($table, ['invoices', 'payments', 'payment_allocations', 'journal_entries', 'credit_notes', 'refunds', 'expenses', 'vendor_payments', 'money_movements'], true))
                <p class="alert alert-warn text-xs">This record is part of your accounting. Overwriting it does not re-post ledger entries — review balances afterwards, or restore the whole business to a backup point instead.</p>
            @endif
            <div><button class="btn btn-primary">Restore</button></div>
        </form>
        @elseif (! $error)
            <div class="card card-body text-sm text-slate-500">This record was deleted. Choose an earlier version to preview and restore it.</div>
        @endif
    </div>
</div>
@endsection
