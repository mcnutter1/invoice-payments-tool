@extends('layouts.app')
@php
    $editing = (bool) $expense;
    $val = fn (string $k, $default = null) => old($k, $editing ? ($expense->{$k} instanceof \DateTimeInterface ? $expense->{$k}->toDateString() : $expense->{$k}) : ($preset[$k] ?? $default));
    $lines = old('lines', $editing
        ? $expense->lines->map(fn ($l) => ['account_id' => $l->account_id, 'description' => $l->description, 'amount' => number_format($l->amount_cents / 100, 2, '.', ''), 'billable' => $l->billable])->all()
        : [[]]);
@endphp
@section('title', $editing ? 'Edit '.$kind : ($kind === 'bill' ? 'New bill' : 'New expense'))
@section('content')
<h1>{{ $editing ? 'Edit '.($kind === 'bill' ? 'bill' : 'expense') : ($kind === 'bill' ? 'New bill (pay later)' : 'New expense (already paid)') }}</h1>
@if ($editing && $expense->source === 'import')
    <p class="mt-1 text-sm text-slate-500">Imported from {{ $expense->import_meta['file'] ?? 'a CSV' }}@if(!empty($expense->import_meta['statement'])) · “{{ $expense->import_meta['statement'] }}”@endif. Changing the amount, date, category or account posts a correcting journal entry.</p>
@elseif ($editing)
    <p class="mt-1 text-sm text-slate-500">Changing the amount, date, vendor, category or paid-from account reverses the original journal entry and posts a corrected one.</p>
@endif
<form method="post" action="{{ $bu }}/expenses{{ $editing ? '/'.$expense->id : '' }}" enctype="multipart/form-data" class="mt-4 grid max-w-4xl gap-4">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="vendor_id">Vendor</label>
            <select class="input" id="vendor_id" name="vendor_id"><option value="">—</option>@foreach ($vendors as $v)<option value="{{ $v->id }}" data-account="{{ $v->default_account_id }}" @selected($val('vendor_id') == $v->id)>{{ $v->name }}</option>@endforeach</select>
            <input class="input mt-1" name="new_vendor" placeholder="…or new vendor name" aria-label="New vendor name" value="{{ old('new_vendor') }}"></div>
        <div><label class="label" for="expense_date">{{ $kind === 'bill' ? 'Bill date' : 'Date paid' }}</label><input class="input" type="date" id="expense_date" name="expense_date" required value="{{ $val('expense_date', $biz->localToday()->toDateString()) }}"></div>
        @if ($kind === 'bill')
            <div><label class="label" for="due_date">Due date</label><input class="input" type="date" id="due_date" name="due_date" value="{{ $val('due_date') }}"></div>
        @else
            <div><label class="label" for="payment_account_id">Paid from</label><select class="input" id="payment_account_id" name="payment_account_id" required>@foreach ($funding as $a)<option value="{{ $a->id }}" @selected($val('payment_account_id') == $a->id)>{{ $a->name }}{{ $a->system_key === 'owner_contributions' ? ' (paid personally by owner)' : '' }}</option>@endforeach</select></div>
        @endif
        <div><label class="label" for="reference">Reference / bill #</label><input class="input" id="reference" name="reference" value="{{ $val('reference') }}"></div>
        <div><label class="label" for="description">Description</label><input class="input" id="description" name="description" value="{{ $val('description') }}"></div>
        <div></div>
        <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id"><option value="">General business (no client)</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected($val('client_id') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
        <div><label class="label" for="project_id">Project / trip</label><select class="input" id="project_id" name="project_id" data-project-select="#client_id"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}" data-client="{{ $p->client_id }}" @selected($val('project_id') == $p->id)>{{ $p->label() }}</option>@endforeach</select>
            <p class="hint">Group related costs, e.g. “NYC meeting with Mavericks Group”. <a href="{{ $bu }}/projects/new">New project</a></p></div>
    </div>
    <div class="card overflow-x-auto">
        <div class="card-head"><h2>Categories</h2><span class="text-xs text-slate-500">Split across categories if needed</span></div>
        <table class="tbl" data-lines><thead><tr><th>Category</th><th>Description</th><th class="w-36">Amount ($)</th><th class="w-24">Billable</th><th class="w-10"></th></tr></thead><tbody>
        @foreach ($lines as $i => $l)
            @include('expenses._line', ['i' => $i, 'l' => $l])
        @endforeach
        </tbody></table>
        <template id="line-template">@include('expenses._line', ['i' => '__I__', 'l' => []])</template>
        <div class="card-body pt-3"><button type="button" class="btn btn-sm" data-add-line>+ Split</button> <span class="ml-3 text-sm">Total: <strong data-expense-total>$0.00</strong></span></div>
    </div>
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="receipt">{{ $editing && $expense->receipt_document_id ? 'Replace receipt' : 'Receipt (PDF, PNG, JPEG)' }}</label><input class="input" type="file" id="receipt" name="receipt" accept="application/pdf,image/png,image/jpeg"></div>
        <div><label class="label" for="notes">Private notes</label><textarea class="input" id="notes" name="notes" rows="2">{{ $val('notes') }}</textarea></div>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Save '.$kind }}</button><a class="btn" href="{{ $bu }}/expenses{{ $editing ? '/'.$expense->id : '' }}">Cancel</a></div>
</form>
@endsection
