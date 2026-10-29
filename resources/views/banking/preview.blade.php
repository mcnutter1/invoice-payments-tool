@extends('layouts.app')
@section('title', 'Import preview')
@section('content')
<h1>Import into {{ $account->name }}</h1>
<p class="mt-1 text-sm text-slate-500">Map the columns, check the preview, then import. Imported rows are staged for review — nothing posts to the ledger until you match or categorize them.</p>
<form method="get" class="card card-body mt-4 grid gap-3 sm:grid-cols-4">
    @php $opt = fn ($sel, $allowNone = false) => ($allowNone ? '<option value="">—</option>' : '').collect($header)->map(fn ($h, $i) => '<option value="'.$i.'" '.($sel === $i ? 'selected' : '').'>'.e($h).'</option>')->implode(''); @endphp
    <div><label class="label" for="map_date">Date column</label><select class="input" id="map_date" name="map_date">{!! $opt($mapping['date']) !!}</select></div>
    <div><label class="label" for="map_description">Description column</label><select class="input" id="map_description" name="map_description">{!! $opt($mapping['description']) !!}</select></div>
    <div><label class="label" for="map_amount">Amount column (signed)</label><select class="input" id="map_amount" name="map_amount">{!! $opt($mapping['amount'], true) !!}</select></div>
    <div><label class="label" for="date_format">Date format</label><select class="input" id="date_format" name="date_format">@foreach (['' => 'Auto-detect', 'm/d/Y' => 'MM/DD/YYYY', 'm/d/y' => 'MM/DD/YY', 'Y-m-d' => 'YYYY-MM-DD', 'd/m/Y' => 'DD/MM/YYYY'] as $k => $v)<option value="{{ $k }}" @selected($mapping['date_format'] === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label class="label" for="map_debit">…or Debit / withdrawal column</label><select class="input" id="map_debit" name="map_debit">{!! $opt($mapping['debit'], true) !!}</select></div>
    <div><label class="label" for="map_credit">Credit / deposit column</label><select class="input" id="map_credit" name="map_credit">{!! $opt($mapping['credit'], true) !!}</select></div>
    <label class="flex items-center gap-2 self-end text-sm"><input class="check" type="checkbox" name="invert" value="1" @checked($mapping['invert'])> Flip signs (card exports with charges positive)</label>
    <div class="self-end"><button class="btn">Update preview</button></div>
</form>
@php $ok = collect($rows)->filter(fn ($r) => ! $r['error'] && ! $r['duplicate']); @endphp
<div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
    <span class="chip chip-green">{{ $ok->count() }} new</span>
    <span class="chip chip-gray">{{ collect($rows)->where('duplicate', true)->count() }} duplicates (skipped)</span>
    <span class="chip chip-red">{{ collect($rows)->filter(fn ($r) => $r['error'])->count() }} errors (skipped)</span>
    <span>Net of new rows: <strong>@money($ok->sum('amount_cents'))</strong></span>
</div>
<div class="card mt-3 max-h-[28rem] overflow-auto">
    <table class="tbl"><thead><tr><th>Row</th><th>Date</th><th>Description</th><th class="num">Amount</th><th>Check</th></tr></thead><tbody>
    @foreach (array_slice($rows, 0, 300) as $r)
        <tr><td>{{ $r['row'] }}</td><td>{{ $r['date'] }}</td><td>{{ $r['description'] }}</td><td class="num">@money($r['amount_cents'])</td>
            <td>@if($r['error'])<span class="chip chip-red">{{ $r['error'] }}</span>@elseif($r['duplicate'])<span class="chip chip-gray">Already imported</span>@else<span class="chip chip-green">OK</span>@endif</td></tr>
    @endforeach
    </tbody></table>
</div>
<form method="post" action="{{ $bu }}/banking/{{ $account->id }}/import/{{ $doc }}" class="mt-4">
    @csrf
    @foreach (['map_date' => $mapping['date'], 'map_description' => $mapping['description'], 'map_amount' => $mapping['amount'], 'map_debit' => $mapping['debit'], 'map_credit' => $mapping['credit'], 'date_format' => $mapping['date_format']] as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
    @if ($mapping['invert'])<input type="hidden" name="invert" value="1">@endif
    <button class="btn btn-primary" @disabled($ok->isEmpty())>Import {{ $ok->count() }} rows</button>
    <a class="btn" href="{{ $bu }}/banking/{{ $account->id }}">Cancel</a>
</form>
@endsection
