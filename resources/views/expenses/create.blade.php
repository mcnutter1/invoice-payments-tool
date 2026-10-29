@extends('layouts.app')
@section('title', $kind === 'bill' ? 'New bill' : 'New expense')
@section('content')
<h1>{{ $kind === 'bill' ? 'New bill (pay later)' : 'New expense (already paid)' }}</h1>
<form method="post" action="{{ $bu }}/expenses" enctype="multipart/form-data" class="mt-4 grid max-w-4xl gap-4">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="vendor_id">Vendor</label>
            <select class="input" id="vendor_id" name="vendor_id"><option value="">—</option>@foreach ($vendors as $v)<option value="{{ $v->id }}" data-account="{{ $v->default_account_id }}" @selected(old('vendor_id') == $v->id)>{{ $v->name }}</option>@endforeach</select>
            <input class="input mt-1" name="new_vendor" placeholder="…or new vendor name" aria-label="New vendor name" value="{{ old('new_vendor') }}"></div>
        <div><label class="label" for="expense_date">{{ $kind === 'bill' ? 'Bill date' : 'Date paid' }}</label><input class="input" type="date" id="expense_date" name="expense_date" required value="{{ old('expense_date', $biz->localToday()->toDateString()) }}"></div>
        @if ($kind === 'bill')
            <div><label class="label" for="due_date">Due date</label><input class="input" type="date" id="due_date" name="due_date" value="{{ old('due_date') }}"></div>
        @else
            <div><label class="label" for="payment_account_id">Paid from</label><select class="input" id="payment_account_id" name="payment_account_id" required>@foreach ($funding as $a)<option value="{{ $a->id }}" @selected(old('payment_account_id') == $a->id)>{{ $a->name }}{{ $a->system_key === 'owner_contributions' ? ' (paid personally by owner)' : '' }}</option>@endforeach</select></div>
        @endif
        <div><label class="label" for="reference">Reference / bill #</label><input class="input" id="reference" name="reference" value="{{ old('reference') }}"></div>
        <div><label class="label" for="description">Description</label><input class="input" id="description" name="description" value="{{ old('description') }}"></div>
        <div><label class="label" for="client_id">Client (optional)</label><select class="input" id="client_id" name="client_id"><option value="">—</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
    </div>
    <div class="card overflow-x-auto">
        <div class="card-head"><h2>Categories</h2><span class="text-xs text-slate-500">Split across categories if needed</span></div>
        <table class="tbl" data-lines><thead><tr><th>Category</th><th>Description</th><th class="w-36">Amount ($)</th><th class="w-24">Billable</th><th class="w-10"></th></tr></thead><tbody>
        @foreach (old('lines', [[]]) as $i => $l)
            @include('expenses._line', ['i' => $i, 'l' => $l])
        @endforeach
        </tbody></table>
        <template id="line-template">@include('expenses._line', ['i' => '__I__', 'l' => []])</template>
        <div class="card-body pt-3"><button type="button" class="btn btn-sm" data-add-line>+ Split</button> <span class="ml-3 text-sm">Total: <strong data-expense-total>$0.00</strong></span></div>
    </div>
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="receipt">Receipt (PDF, PNG, JPEG)</label><input class="input" type="file" id="receipt" name="receipt" accept="application/pdf,image/png,image/jpeg"></div>
        <div><label class="label" for="notes">Private notes</label><textarea class="input" id="notes" name="notes" rows="2">{{ old('notes') }}</textarea></div>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Save {{ $kind }}</button><a class="btn" href="{{ $bu }}/expenses">Cancel</a></div>
</form>
@endsection
