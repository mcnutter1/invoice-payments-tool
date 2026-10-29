@extends('layouts.app')
@section('title', 'Period close')
@section('content')
@include('accounting._tabs')
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <div class="card card-body">
        <h2>Books closed through</h2>
        <p class="mt-1 text-2xl font-semibold">{{ $biz->books_closed_through?->format('M j, Y') ?? 'Not closed' }}</p>
        <p class="hint">No posting, reversal or correction can be dated on or before this date while it is closed. Reports for closed periods stay reproducible.</p>
        @if ($ctx->can('books.close'))
        <form method="post" action="{{ $bu }}/periods/close" class="mt-4 flex flex-wrap items-end gap-2" data-confirm="Close the books through this date?">@csrf
            <div><label class="label" for="closed_through">Close through</label><input class="input" type="date" id="closed_through" name="closed_through" required value="{{ $biz->localToday()->subMonthNoOverflow()->endOfMonth()->toDateString() }}"></div>
            <div class="flex-1"><label class="label" for="creason">Note</label><input class="input" id="creason" name="reason"></div>
            <button class="btn btn-primary">Close period</button>
        </form>
        @endif
        @if ($ctx->can('books.reopen') && $biz->books_closed_through)
        <form method="post" action="{{ $bu }}/periods/reopen" class="mt-4 flex flex-wrap items-end gap-2" data-confirm="Reopen closed books? This is audited.">@csrf
            <div><label class="label" for="rclosed">New close date (blank = fully open)</label><input class="input" type="date" id="rclosed" name="closed_through"></div>
            <div class="flex-1"><label class="label" for="rreason">Reason (required)</label><input class="input" id="rreason" name="reason" required></div>
            <button class="btn btn-danger">Reopen</button>
        </form>
        @endif
    </div>
    <div class="card"><div class="card-head"><h2>History</h2></div>
        <ul class="card-body space-y-1 text-sm">
            @forelse ($history as $h)<li>{{ $h->created_at->timezone($biz->timezone)->format('M j, Y g:ia') }} · {{ ucfirst($h->action) }} {{ $h->closed_through ? 'through '.$h->closed_through->format('M j, Y') : '(fully open)' }} · {{ $h->actor->label() }} @if($h->reason)— {{ $h->reason }}@endif</li>
            @empty<li class="text-slate-500">No close history.</li>@endforelse
        </ul>
    </div>
</div>
@endsection
