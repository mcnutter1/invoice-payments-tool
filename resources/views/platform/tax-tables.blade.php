@extends('layouts.platform')
@section('title', 'Application settings · Tax tables')
@section('content')
<h1>Tax tables</h1>
<p class="mt-1 text-sm text-slate-600">Brackets, deductions and limits used by every business’s tax estimate. Built-in values ship with the app; newer figures are checked weekly from
    @foreach ($sources as $src)@if($src)<a href="{{ $src }}" target="_blank" rel="noopener">{{ parse_url($src, PHP_URL_HOST) }}</a>@if(! $loop->last), @endif @endif @endforeach
    and stored here as overrides. State tables can be added as JSON.</p>
<div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
    <form method="post" action="/platform/tax-tables/sync">@csrf<button class="btn">Check for updates now</button></form>
    @if ($lastSync)
        <span class="{{ ($lastSync['ok'] ?? false) ? 'text-slate-600' : 'text-red-700' }}">Last check {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->diffForHumans() }}:
            {{ $lastSync['changes'] ? 'updated '.implode(', ', $lastSync['changes']) : 'no changes' }}{{ $lastSync['errors'] ? ' — '.implode('; ', $lastSync['errors']) : '' }}</span>
    @else
        <span class="text-slate-500">Not checked yet.</span>
    @endif
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <form method="get" class="card card-body flex flex-wrap items-end gap-2">
            <div><label class="label" for="j">Jurisdiction</label><select class="input" id="j" name="jurisdiction">@foreach ($jurisdictions as $jj)<option @selected($jj === $jurisdiction)>{{ $jj }}</option>@endforeach</select></div>
            <div><label class="label" for="y">Year</label><input class="input w-28" id="y" name="year" type="number" value="{{ $year }}"></div>
            <button class="btn">Show effective values</button>
        </form>
        <div class="card">
            <div class="card-head"><h2>Effective {{ $jurisdiction }} {{ $year }}</h2>
                @if ($selected && $selected['carried'])<span class="chip chip-amber">Carried forward: {{ implode(', ', $selected['carried']) }}</span>@elseif($selected)<span class="chip chip-green">Current</span>@endif</div>
            <div class="card-body text-sm">
                @if ($selected)
                    <p class="text-xs text-slate-500">Layers (oldest first): @foreach ($selected['layers'] as $l){{ $l['year'] }} · {{ $l['source'] }}@if($l['notes']) ({{ $l['notes'] }})@endif @if(! $loop->last) → @endif @endforeach</p>
                    <pre class="mt-2 max-h-[32rem] overflow-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode($selected['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                @else
                    <p class="text-slate-500">Nothing loaded for {{ $jurisdiction }} on or before {{ $year }}.</p>
                @endif
            </div>
        </div>
        <div class="card overflow-x-auto">
            <div class="card-head"><h2>Stored overrides</h2></div>
            <table class="tbl"><thead><tr><th>Jurisdiction</th><th>Year</th><th>Keys</th><th>Source</th><th>Updated</th><th></th></tr></thead><tbody>
            @forelse ($rows as $r)
                <tr><td>{{ $r->jurisdiction }}</td><td>{{ $r->year }}</td><td class="text-xs">{{ implode(', ', array_keys($r->data)) }}</td>
                    <td><span class="chip {{ in_array($r->source, ['irs.gov', 'ssa.gov'], true) ? 'chip-green' : ($r->source === 'manual' ? 'chip-violet' : 'chip-gray') }}">{{ $r->source }}</span>@if($r->notes)<div class="text-xs text-slate-500">{{ $r->notes }}</div>@endif</td>
                    <td class="text-xs">{{ $r->updated_at->diffForHumans() }}</td>
                    <td class="text-right"><form method="post" action="/platform/tax-tables/{{ $r->id }}/delete" data-confirm="Remove this override?">@csrf<button class="btn btn-ghost btn-sm">Remove</button></form></td></tr>
            @empty
                <tr><td colspan="6" class="text-slate-500">Only built-in values are in use.</td></tr>
            @endforelse
            </tbody></table>
        </div>
    </div>
    <form method="post" action="/platform/tax-tables" class="card card-body space-y-3">@csrf
        <h2>Add or update a table</h2>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="label" for="ij">Jurisdiction</label><input class="input uppercase" id="ij" name="jurisdiction" value="{{ old('jurisdiction', $jurisdiction) }}" required maxlength="10"></div>
            <div><label class="label" for="iy">Year</label><input class="input" id="iy" name="year" type="number" value="{{ old('year', $year) }}" required></div>
        </div>
        <div><label class="label" for="ijson">JSON (only the keys that change)</label>
            <textarea class="input font-mono text-xs" id="ijson" name="json" rows="12" required placeholder='{"ss_wage_base": 192300}'>{{ old('json') }}</textarea>
            <p class="hint">Dollars as whole numbers, rates as decimals. Brackets are lists of [floor, rate], e.g. <code>{"brackets": {"single": [[0, 0.014], [20000, 0.0175]]}}</code>. A new state needs <code>"type": "progressive"</code> (or <code>"flat"</code> with <code>flat_rate</code>, or <code>"none"</code>).</p></div>
        <div><label class="label" for="iurl">Source URL</label><input class="input" id="iurl" name="source_url" type="url" value="{{ old('source_url') }}" placeholder="https://…"></div>
        <div><label class="label" for="inotes">Notes</label><input class="input" id="inotes" name="notes" value="{{ old('notes') }}" placeholder="e.g. Rev. Proc. 2026-xx"></div>
        <button class="btn btn-primary">Validate and save</button>
    </form>
</div>
@endsection
