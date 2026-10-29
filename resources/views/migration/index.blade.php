@extends('layouts.app')
@section('title', 'Data import')
@section('content')
<h1>Data import &amp; migration</h1>
<p class="mt-1 max-w-3xl text-sm text-slate-500">Move from QuickBooks or spreadsheets using CSV templates. Every import shows a dry-run preview first, skips duplicates by key, and is tracked as a batch. See docs/MIGRATION.md for the QuickBooks export mapping and the cutoff policy.</p>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <form method="post" action="{{ $bu }}/migration" enctype="multipart/form-data" class="card card-body space-y-3">@csrf
        <h2>Upload a CSV</h2>
        <div><label class="label" for="kind">What are you importing?</label><select class="input" id="kind" name="kind">@foreach ($kinds as $k => [$label])<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
        <div><label class="label" for="cutoff">Cutoff date (opening A/R only)</label><input class="input" type="date" id="cutoff" name="cutoff"><p class="hint">The day before you start using this app. Opening balances post on this date.</p></div>
        <div><label class="label" for="csv">CSV file</label><input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required></div>
        <button class="btn btn-primary">Preview import</button>
    </form>
    <div class="card card-body space-y-2 text-sm">
        <h2>Templates</h2>
        @foreach ($kinds as $k => [$label, $cols])
            <div class="flex items-start justify-between gap-2"><div><div class="font-medium">{{ $label }}</div><div class="font-mono text-xs text-slate-500">{{ implode(', ', $cols) }}</div></div><a class="btn btn-sm" href="{{ $bu }}/migration/template/{{ $k }}">Template</a></div>
        @endforeach
        <p class="hint">Recommended order: clients → products &amp; services → opening A/R → historical expenses. Opening bank balances are entered when adding a bank account; other opening balances use a journal entry against Opening Balance Equity.</p>
    </div>
</div>
<div class="card mt-4 overflow-x-auto"><div class="card-head"><h2>Import history</h2></div>
    <table class="tbl"><thead><tr><th>When</th><th>Type</th><th>File</th><th class="num">Imported</th><th class="num">Duplicates</th><th class="num">Errors</th></tr></thead><tbody>
    @forelse ($batches as $b)<tr><td>{{ $b->created_at->timezone($biz->timezone)->format('M j, Y g:ia') }}</td><td>{{ str_replace('migration_', '', $b->kind) }}</td><td>{{ $b->filename }}</td><td class="num">{{ $b->imported_count }}</td><td class="num">{{ $b->duplicate_count }}</td><td class="num">{{ $b->error_count }}</td></tr>
    @empty<tr><td colspan="6" class="text-slate-500">No imports yet.</td></tr>@endforelse
    </tbody></table></div>
@endsection
