@extends('layouts.app')
@section('title', 'Journal entry #'.$entry->id)
@section('content')
@include('accounting._tabs')
<div class="card mt-4">
    <div class="card-head"><div><h2>Entry #{{ $entry->id }} · {{ $entry->entry_date->format('M j, Y') }}</h2><p class="text-sm text-slate-500">{{ $entry->memo }}</p></div>
        <span>@if($entry->kind === 'manual')<span class="chip chip-violet">Manual</span>@else<span class="chip chip-gray">Automatic</span>@endif</span></div>
    <table class="tbl"><thead><tr><th>Account</th><th>Client / vendor</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
        @foreach ($entry->lines as $l)
            <tr><td>{{ $l->account->label() }}</td><td>{{ $l->client?->display_name ?? $l->vendor?->name }}</td><td>{{ $l->description }}</td>
                <td class="num">{{ $l->debit_cents ? \App\Support\Money::format($l->debit_cents) : '' }}</td><td class="num">{{ $l->credit_cents ? \App\Support\Money::format($l->credit_cents) : '' }}</td></tr>
        @endforeach
        <tr class="font-semibold"><td colspan="3">Total</td><td class="num">@money($entry->lines->sum('debit_cents'))</td><td class="num">@money($entry->lines->sum('credit_cents'))</td></tr>
    </tbody></table>
    <div class="card-body text-sm text-slate-600">
        Source: {{ $entry->source_type ?? '—' }} {{ $entry->source_id ? '#'.$entry->source_id : '' }} · Posted {{ $entry->created_at->timezone($biz->timezone)->format('M j, Y g:ia') }} by {{ $entry->poster?->label() ?? 'system' }}
        @if ($entry->reason)<br>Reason: {{ $entry->reason }}@endif
        @if ($entry->reversal_of_id)<br>Reverses <a href="{{ $bu }}/journals/{{ $entry->reversal_of_id }}">#{{ $entry->reversal_of_id }}</a>@endif
        @if ($reversedBy)<br>Reversed by <a href="{{ $bu }}/journals/{{ $reversedBy->id }}">#{{ $reversedBy->id }}</a>@endif
    </div>
</div>
@if ($entry->kind === 'manual' && ! $reversedBy && ! $entry->reversal_of_id && $ctx->can('journals.manage'))
    <form method="post" action="{{ $bu }}/journals/{{ $entry->id }}/reverse" class="card card-body mt-4 flex flex-wrap items-end gap-2" data-confirm="Post a reversing entry?">@csrf
        <div><label class="label" for="rdate">Reversal date</label><input class="input" id="rdate" type="date" name="date" value="{{ $biz->localToday()->toDateString() }}"></div>
        <div class="flex-1"><label class="label" for="rreason">Reason</label><input class="input" id="rreason" name="reason" required></div>
        <button class="btn btn-danger">Reverse entry</button>
    </form>
@endif
<p class="hint mt-3">Posted entries are never edited or deleted. Corrections use reversing or adjusting entries.</p>
@endsection
