@extends('layouts.app')
@section('title', 'Review import')
@php
    $labels = ['new' => ['New', 'chip-green'], 'match' => ['Matches existing', 'chip-amber'], 'imported' => ['Already imported', 'chip-gray'],
        'refund' => ['Refund / credit', 'chip-gray'], 'pending' => ['Pending', 'chip-violet'], 'error' => ['Error', 'chip-red']];
    $counts = collect($p['rows'])->countBy('status');
@endphp
@section('content')
@include('expenses._tabs', ['active' => 'import'])
<div class="flex flex-wrap items-center justify-between gap-3">
    <div><h1>Review import</h1><p class="text-sm text-slate-500">{{ $snapshot ? \App\Services\Expenses\Subscriptions\SubscriptionChargeImporter::label($snapshot) : $doc->original_name }} · {{ count($p['rows']) }} {{ $snapshot ? 'charges' : 'rows' }}</p></div>
    <div class="flex flex-wrap gap-1 text-sm">@foreach ($labels as $k => [$t, $c]) @if($counts[$k] ?? 0)<span class="chip {{ $c }}">{{ $counts[$k] }} {{ strtolower($t) }}</span>@endif @endforeach</div>
</div>
<form method="post" action="{{ $bu }}/expenses/import/{{ $doc->ulid }}" class="mt-4 space-y-4">@csrf
    @if ($snapshot && collect($snapshot['sources'])->contains('mode', 'sample'))
        <p class="alert alert-warn">{{ collect($snapshot['sources'])->where('mode', 'sample')->pluck('name')->implode(', ') }} {{ collect($snapshot['sources'])->where('mode', 'sample')->count() === 1 ? 'uses' : 'use' }} a sample connector. Import into a test business, or wait until the live connection is set up.</p>
    @endif
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card overflow-x-auto">
            <div class="card-head"><h2>Categories</h2><span class="text-xs text-slate-500">Source category → your expense category</span></div>
            <table class="tbl"><thead><tr><th>Source category</th><th class="num">Rows</th><th>Expense category</th></tr></thead><tbody>
            @foreach ($p['categories'] as $c)
                <tr><td>{{ $c['name'] }}<div class="text-xs text-slate-500">@money($c['cents'])</div></td><td class="num">{{ $c['count'] }}</td>
                    <td><select class="input input-sm" name="categories[{{ base64_encode($c['name']) }}]" aria-label="Category for {{ $c['name'] }}">@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(($catMap[$c['name']] ?? null) == $a->id)>{{ $a->label() }}</option>@endforeach</select></td></tr>
            @endforeach
            </tbody></table>
        </div>
        <div class="space-y-4">
            <div class="card overflow-x-auto">
                <div class="card-head"><h2>Accounts</h2><span class="text-xs text-slate-500">Card / bank → paid from</span></div>
                <table class="tbl"><thead><tr><th>Source account</th><th class="num">Rows</th><th>Paid from</th></tr></thead><tbody>
                @foreach ($p['accounts'] as $a)
                    <tr><td>{{ $a['name'] }}</td><td class="num">{{ $a['count'] }}</td>
                        <td><select class="input input-sm" name="accounts[{{ base64_encode($a['name']) }}]" aria-label="Paid-from for {{ $a['name'] }}">@foreach ($funding as $f)<option value="{{ $f->id }}" @selected(($acctMap[$a['name']] ?? null) == $f->id)>{{ $f->name }}{{ $f->system_key === 'owner_contributions' ? ' (paid personally)' : '' }}</option>@endforeach</select></td></tr>
                @endforeach
                </tbody></table>
            </div>
            <div class="card card-body grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="client_id">Assign client</label><select class="input" id="client_id" name="client_id"><option value="">General business</option>@foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->display_name }}</option>@endforeach</select></div>
                <div><label class="label" for="project_id">Assign project</label><select class="input" id="project_id" name="project_id" data-project-select="#client_id"><option value="">—</option>@foreach ($projects as $pr)<option value="{{ $pr->id }}" data-client="{{ $pr->client_id }}">{{ $pr->label() }}</option>@endforeach</select></div>
                @if (count($p['entities']) > 1)
                    <div class="sm:col-span-2"><label class="label" for="entity">Business entity</label><select class="input" id="entity" name="entity"><option value="">All entities</option>@foreach ($p['entities'] as $en)<option value="{{ $en }}">Only “{{ $en }}”</option>@endforeach</select></div>
                @endif
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" class="check" name="remember" value="1" checked> Remember these mappings for future imports</label>
                <p class="hint sm:col-span-2">You can assign individual expenses to a client or project after importing.</p>
            </div>
        </div>
    </div>
    <div class="card overflow-x-auto">
        <div class="card-head"><h2>Transactions</h2><span class="text-xs text-slate-500">Flagged matches are unchecked; check them to import anyway.</span></div>
        <table class="tbl"><thead><tr><th class="w-8"><input type="checkbox" class="check" data-check-all="rows" aria-label="Select all"></th><th>Date</th><th>Merchant</th><th>Category</th><th>Account</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
        @foreach ($p['rows'] as $r)
            @php
                [$t, $c] = $labels[$r['status']];
                $selectable = in_array($r['status'], ['new', 'match', 'pending'], true);
            @endphp
            <tr @class(['opacity-50' => ! $selectable])>
                <td><input type="checkbox" class="check" name="include[]" value="{{ $r['n'] }}" data-check="rows" @checked($r['status'] === 'new') @disabled(! $selectable) aria-label="Import row {{ $r['n'] + 1 }}"></td>
                <td class="whitespace-nowrap">{{ $r['date'] ? \Illuminate\Support\Carbon::parse($r['date'])->format('M j, Y') : '—' }}</td>
                <td>{{ $r['merchant'] }}@if($r['statement'] && $r['statement'] !== $r['merchant'])<div class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($r['statement'], 70) }}</div>@endif @if($r['notes'])<div class="text-xs text-slate-500">{{ $r['notes'] }}</div>@endif
                    @if (! empty($r['lines']) && count($r['lines']) > 1)
                        <details class="mt-1 text-xs"><summary class="cursor-pointer text-slate-500">{{ count($r['lines']) }} line items</summary>
                            <table class="mt-1 w-full">@foreach ($r['lines'] as $l)<tr><td class="py-0.5 pr-3 text-slate-600">{{ $l['description'] }}</td><td class="num py-0.5 tabular-nums">@money($l['amount_cents'])</td></tr>@endforeach</table>
                        </details>
                    @endif</td>
                <td>{{ $r['category'] }}</td>
                <td class="text-sm">{{ $r['account'] }}</td>
                <td class="num">@money($r['cents'])</td>
                <td><span class="chip {{ $c }}">{{ $t }}</span>@if($r['existing_id']) <a class="text-xs" href="{{ $bu }}/expenses/{{ $r['existing_id'] }}" target="_blank" rel="noopener">#{{ $r['existing_id'] }}</a>@endif @if($r['error'])<div class="text-xs text-red-700">{{ $r['error'] }}</div>@endif</td>
            </tr>
        @endforeach
        </tbody></table>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Import selected</button><a class="btn" href="{{ $bu }}/expenses/import">Cancel</a></div>
</form>
@endsection
