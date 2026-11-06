@extends('layouts.app')
@section('title', $account->name)
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div><h1>{{ $account->name }}</h1><p class="text-sm text-slate-500">Ledger balance: <strong>@money($account->chartAccount->type === 'asset' ? $ledgerBalance : -$ledgerBalance)</strong> · {{ $account->chartAccount->label() }}</p></div>
    @if ($ctx->can('banking.manage'))
    <div class="flex flex-wrap gap-2">
        <form method="post" action="{{ $bu }}/banking/{{ $account->id }}/import" enctype="multipart/form-data" class="flex items-center gap-2">@csrf
            <label for="csv" class="sr-only">CSV file</label><input type="file" id="csv" name="csv" accept=".csv,text/csv" class="input input-sm w-56" required>
            <button class="btn">Import CSV</button></form>
    </div>
    @endif
</div>
@include('partials.tabs', ['label' => 'Row filters', 'class' => 'mt-4', 'tabs' => collect(['unmatched' => 'To review', 'matched' => 'Matched', 'posted' => 'Posted', 'excluded' => 'Excluded', 'all' => 'All'])
    ->map(fn ($v, $k) => [$v, '?status='.$k, $status === $k])->values()->all()])
<div class="card mt-3 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th>Status</th><th class="min-w-[22rem]">Action</th></tr></thead><tbody>
    @forelse ($txns as $t)
        <tr>
            <td>{{ $t->posted_date->format('M j, Y') }}</td>
            <td>{{ $t->description }}</td>
            <td class="num {{ $t->amount_cents < 0 ? 'text-red-700' : 'text-emerald-700' }}">@money($t->amount_cents)</td>
            <td>@include('partials.status', ['s' => $t->status])</td>
            <td>
                @if ($t->status === 'unmatched' && $ctx->can('banking.manage'))
                    @foreach ($suggestions[$t->id] ?? [] as $s)
                        <form method="post" action="{{ $bu }}/bank-transactions/{{ $t->id }}/match" class="mb-1">@csrf<input type="hidden" name="journal_line_id" value="{{ $s->id }}">
                            <button class="btn btn-sm">Match: {{ $s->entry_date->format('M j') }} · {{ \Illuminate\Support\Str::limit($s->entry->memo, 40) }}</button></form>
                    @endforeach
                    <form method="post" action="{{ $bu }}/bank-transactions/{{ $t->id }}/categorize" class="flex flex-wrap gap-1">@csrf
                        <select name="account_id" class="input input-sm w-56" aria-label="Category">
                            @foreach ($categories->groupBy('type') as $type => $accts)
                                <optgroup label="{{ ucfirst($type) }}">@foreach ($accts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</optgroup>
                            @endforeach
                        </select>
                        <button class="btn btn-sm">Post new</button>
                    </form>
                    <form method="post" action="{{ $bu }}/bank-transactions/{{ $t->id }}/exclude" class="mt-1">@csrf<button class="btn btn-ghost btn-sm">Exclude</button></form>
                @elseif (in_array($t->status, ['matched', 'posted', 'excluded']) && $ctx->can('banking.manage'))
                    @if ($t->matched_journal_line_id && $ctx->can('journals.view'))<a class="text-sm" href="{{ $bu }}/journals/{{ $t->matchedLine?->journal_entry_id }}">Ledger entry</a> · @endif
                    <form method="post" action="{{ $bu }}/bank-transactions/{{ $t->id }}/undo" class="inline" data-confirm="Undo? {{ $t->status === 'posted' ? 'The posting created from this row will be reversed.' : '' }}">@csrf<button class="btn btn-ghost btn-sm">Undo</button></form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="5"><div class="empty m-4">Nothing here. Import a CSV statement to start matching.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $txns->links() }}</div>
<p class="hint mt-2">“Post new” creates a ledger entry from the row (e.g. bank fees, or a transfer to another account). Customer payments and bills should be recorded in Payments/Expenses first, then matched here to avoid double counting.</p>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card"><div class="card-head"><h2>Reconcile</h2></div>
        <div class="card-body">
            @if ($ctx->can('banking.manage'))
            <form method="post" action="{{ $bu }}/banking/{{ $account->id }}/reconcile" class="flex flex-wrap items-end gap-2">@csrf
                <div><label class="label" for="statement_date">Statement end date</label><input class="input" type="date" id="statement_date" name="statement_date" required></div>
                <div><label class="label" for="closing">Ending balance ($)</label><input class="input" id="closing" name="closing" type="number" step="0.01" required></div>
                <div><label class="label" for="opening">Beginning balance ($)</label><input class="input" id="opening" name="opening" type="number" step="0.01" placeholder="Last reconciled"></div>
                <button class="btn btn-primary">Start</button>
            </form>
            @endif
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($reconciliations as $r)<li><a href="{{ $bu }}/reconciliations/{{ $r->id }}">{{ $r->statement_date->format('M j, Y') }}</a> · @money($r->statement_closing_cents) @include('partials.status', ['s' => $r->status])</li>@endforeach
            </ul>
        </div>
    </div>
    <div class="card"><div class="card-head"><h2>Imports</h2></div>
        <ul class="card-body space-y-1 text-sm">
            @forelse ($batches as $bt)
                <li class="flex items-center justify-between gap-2"><span>{{ $bt->created_at->timezone($biz->timezone)->format('M j, Y') }} · {{ $bt->filename }} · {{ $bt->imported_count }} rows, {{ $bt->duplicate_count }} duplicates @include('partials.status', ['s' => $bt->status === 'undone' ? 'undone' : 'posted'])</span>
                    @if ($bt->status !== 'undone' && $ctx->can('banking.manage'))<form method="post" action="{{ $bu }}/imports/{{ $bt->id }}/undo" data-confirm="Remove unmatched rows from this import?">@csrf<button class="btn btn-ghost btn-sm">Undo</button></form>@endif</li>
            @empty <li class="text-slate-500">No imports yet.</li> @endforelse
        </ul>
    </div>
</div>
@endsection
