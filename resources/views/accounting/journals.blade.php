@extends('layouts.app')
@section('title', 'Journal')
@section('content')
@include('accounting._tabs')
<form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="kind">Show</label><select id="kind" name="kind" class="input"><option value="">All entries</option><option value="manual" @selected(request('kind') === 'manual')>Manual only</option></select></div>
    <div><label class="label" for="from">From</label><input id="from" type="date" name="from" class="input" value="{{ request('from') }}"></div>
    <div><label class="label" for="to">To</label><input id="to" type="date" name="to" class="input" value="{{ request('to') }}"></div>
    <button class="btn">Filter</button>
</form>
<div class="mt-4 space-y-3">
    @forelse ($entries as $e)
        <div class="card">
            <div class="card-head text-sm"><a href="{{ $bu }}/journals/{{ $e->id }}" class="font-medium">#{{ $e->id }} · {{ $e->entry_date->format('M j, Y') }} · {{ $e->memo }}</a>
                <span>@if($e->kind === 'manual')<span class="chip chip-violet">Manual</span>@endif @if($e->reversal_of_id)<span class="chip chip-gray">Reversal</span>@endif</span></div>
            <table class="tbl"><tbody>
                @foreach ($e->lines as $l)<tr><td class="w-1/2">{{ $l->account->label() }}</td><td class="num">{{ $l->debit_cents ? \App\Support\Money::format($l->debit_cents) : '' }}</td><td class="num">{{ $l->credit_cents ? \App\Support\Money::format($l->credit_cents) : '' }}</td></tr>@endforeach
            </tbody></table>
        </div>
    @empty
        <div class="empty">No journal entries yet.</div>
    @endforelse
</div>
<div class="mt-4">{{ $entries->links() }}</div>
@endsection
