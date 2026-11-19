@extends('layouts.app')
@section('title', 'Import expenses')
@section('content')
@include('expenses._tabs', ['active' => 'import'])
<h1>Import expenses</h1>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <form method="post" action="{{ $bu }}/expenses/import" enctype="multipart/form-data" class="card card-body space-y-3 lg:col-span-2">@csrf
        <h2>Upload a transactions CSV</h2>
        <p class="text-sm text-slate-600">Export transactions from <strong>Monarch Money</strong> (Transactions → Export). Any CSV with <code>Date</code>, <code>Merchant</code>, <code>Category</code>, <code>Account</code> and <code>Amount</code> columns also works.</p>
        <input class="input" type="file" name="csv" accept=".csv,text/csv" required aria-label="CSV file">
        <div><button class="btn btn-primary">Upload and review</button></div>
        <p class="hint">Nothing is recorded until you review the rows and confirm. Rows already imported (same transaction ID) or matching an expense you entered by hand are flagged and skipped by default. Refunds and pending transactions are skipped.</p>
    </form>
    <div class="card card-body text-sm space-y-2">
        <h2>How it maps</h2>
        <p>Each source <strong>category</strong> maps to one of your expense categories, and each <strong>account</strong> (card or checking) to the account it was paid from. Your choices are remembered for next time.</p>
        <p>Merchants become vendors. Imported expenses are marked <span class="chip chip-blue">Imported</span> and can be edited afterwards.</p>
    </div>
</div>
<form id="subscriptions" method="post" action="{{ $bu }}/expenses/import/subscriptions" class="card mt-6 scroll-mt-4">@csrf
    <div class="card-head"><div><h2>Import subscription charges</h2><p class="text-xs text-slate-500">Recurring bills pulled from the services you pay every cycle, itemized as billed.</p></div></div>
    <div class="card-body space-y-4">
        @error('sources')<p class="field-error">{{ $message }}</p>@enderror
        <div class="grid gap-3 md:grid-cols-3">
        @foreach ($sources as $s)
            <label class="flex cursor-pointer gap-3 rounded-lg border border-slate-200 p-4 hover:border-slate-300 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                <input class="check mt-0.5" type="checkbox" name="sources[]" value="{{ $s['key'] }}" @checked($s['configured']) @disabled(! $s['configured'])>
                <span class="min-w-0 space-y-1">
                    <span class="flex flex-wrap items-center gap-2"><span class="font-semibold text-slate-900">{{ $s['name'] }}</span>
                        @if ($s['mode'] === 'sample')<span class="chip chip-amber" title="Built-in sample connector — no live account is connected yet">Sample data</span>@else<span class="chip chip-green">Connected</span>@endif</span>
                    <span class="block text-sm text-slate-600">{{ $s['summary'] }}</span>
                    <span class="block text-xs text-slate-500">
                        @if ($s['next'])Next charge {{ $s['next']->format('M j, Y') }}@endif
                        @if ($s['last_imported']) · last imported {{ $s['last_imported']->format('M j, Y') }} ({{ $s['imported_count'] }})@else · nothing imported yet @endif
                    </span>
                </span>
            </label>
        @endforeach
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div><label class="label" for="since">Charges from</label>
                <select class="input w-auto" id="since" name="since">
                    @foreach (['30' => 'Last 30 days', '90' => 'Last 90 days', '180' => 'Last 6 months', '365' => 'Last 12 months', 'ytd' => 'This year'] as $k => $v)<option value="{{ $k }}" @selected($k === '90')>{{ $v }}</option>@endforeach
                </select></div>
            <button class="btn btn-primary">Pull charges and review</button>
        </div>
        <p class="hint">Charges are fetched and frozen for review; nothing is recorded until you confirm. Charges already imported are recognised by their invoice or bill ID and skipped. Sample connectors stand in until live integrations are connected — review before importing into real books.</p>
    </div>
</form>
<div class="card mt-6 overflow-x-auto">
    <div class="card-head"><h2>Recent imports</h2></div>
    <table class="tbl"><thead><tr><th>Date</th><th>File</th><th class="num">Rows</th><th class="num">Imported</th><th class="num">Duplicates</th><th class="num">Skipped</th><th></th></tr></thead><tbody>
    @forelse ($batches as $bt)
        <tr>
            <td>{{ $bt->created_at->timezone($biz->timezone)->format('M j, Y g:i A') }}</td>
            <td><a href="{{ $bu }}/expenses?batch={{ $bt->id }}">{{ $bt->filename }}</a> @if($bt->undone_at)<span class="chip chip-gray">Undone</span>@endif</td>
            <td class="num">{{ $bt->row_count }}</td><td class="num">{{ $bt->imported_count }}</td><td class="num">{{ $bt->duplicate_count }}</td><td class="num">{{ $bt->error_count }}</td>
            <td class="text-right">@unless($bt->undone_at)<form method="post" action="{{ $bu }}/expenses/imports/{{ $bt->id }}/undo" data-confirm="Void every expense from this import that hasn't been edited or billed?">@csrf<button class="btn btn-sm btn-ghost">Undo</button></form>@endunless</td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-slate-500">No imports yet.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
