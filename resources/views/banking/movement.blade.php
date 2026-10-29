@extends('layouts.app')
@section('title', 'Money movement')
@section('content')
<h1>Transfers, owner &amp; loan activity</h1>
<p class="mt-1 text-sm text-slate-500">These are balance-sheet movements. They never count as income or expense.</p>
<form method="post" action="{{ $bu }}/movements" class="card card-body mt-4 grid max-w-3xl gap-4 sm:grid-cols-2" data-movement-form>
    @csrf
    <div class="sm:col-span-2"><label class="label" for="kind">Type</label>
        <select class="input" id="kind" name="kind" data-movement-kind>@foreach (\App\Models\MoneyMovement::KINDS as $k => $v)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div data-from><label class="label" for="from_account_id">From account</label><select class="input" id="from_account_id" name="from_account_id">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></div>
    <div data-to><label class="label" for="to_account_id">To account</label><select class="input" id="to_account_id" name="to_account_id">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach</select></div>
    <div><label class="label" for="amount">Amount ($)</label><input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" required></div>
    <div><label class="label" for="movement_date">Date</label><input class="input" id="movement_date" name="movement_date" type="date" value="{{ $biz->localToday()->toDateString() }}" required></div>
    <div class="sm:col-span-2"><label class="label" for="memo">Memo</label><input class="input" id="memo" name="memo"></div>
    <p class="hint sm:col-span-2">Owner contribution: money in from the owner. Owner draw: money out to the owner. Loan payment: principal only — record interest as an expense.</p>
    <div><button class="btn btn-primary">Record</button></div>
</form>
@endsection
