@extends('layouts.app')
@php
    $val = fn (string $k, $default = null) => old($k, $trip ? ($trip->{$k} instanceof \DateTimeInterface ? $trip->{$k}->toDateString() : $trip->{$k}) : ($preset[$k] ?? $default));
    $billable = old('billable', $trip?->expense?->lines()->where('billable', true)->exists());
@endphp
@section('title', $trip ? 'Edit trip' : 'New trip')
@section('content')
@include('expenses._tabs', ['active' => 'mileage'])
<h1>{{ $trip ? 'Edit trip' : 'Record a business trip' }}</h1>
<form method="post" action="{{ $bu }}/mileage{{ $trip ? '/'.$trip->id : '' }}" class="mt-4 grid max-w-3xl gap-4" data-mileage-rates="{{ json_encode($rates) }}">@csrf
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="trip_date">Date</label><input class="input" type="date" id="trip_date" name="trip_date" required value="{{ $val('trip_date', $biz->localToday()->toDateString()) }}"></div>
        <div><label class="label" for="purpose">Business purpose</label><input class="input" id="purpose" name="purpose" required maxlength="255" value="{{ $val('purpose') }}" placeholder="e.g. Client meeting at Mavericks Group"></div>
        <div><label class="label" for="from_location">From</label><input class="input" id="from_location" name="from_location" maxlength="200" value="{{ $val('from_location') }}"></div>
        <div><label class="label" for="to_location">To</label><input class="input" id="to_location" name="to_location" maxlength="200" value="{{ $val('to_location') }}"></div>
        <div><label class="label" for="miles">Miles (one way)</label><input class="input" type="number" step="0.1" min="0.1" max="5000" id="miles" name="miles" required value="{{ $val('miles') }}"></div>
        <div class="flex items-end pb-2"><label class="flex items-center gap-2 text-sm"><input type="hidden" name="round_trip" value="0"><input type="checkbox" class="check" name="round_trip" value="1" @checked($val('round_trip'))> Round trip (doubles the miles)</label></div>
        <div class="sm:col-span-2 rounded-lg bg-slate-50 p-3 text-sm" aria-live="polite"><strong>Deduction:</strong> <span data-mileage-quote>—</span></div>
        <div><label class="label" for="vehicle">Vehicle</label><input class="input" id="vehicle" name="vehicle" maxlength="80" list="vehicles" value="{{ $val('vehicle') }}"><datalist id="vehicles">@foreach ($vehicles as $v)<option value="{{ $v }}">@endforeach</datalist></div>
        <div><label class="label" for="payment_account_id">Reimbursed through</label><select class="input" id="payment_account_id" name="payment_account_id">@foreach ($funding as $a)<option value="{{ $a->id }}" @selected(old('payment_account_id', $trip?->expense?->payment_account_id ?? $ownerAccount) == $a->id)>{{ $a->name }}{{ $a->system_key === 'owner_contributions' ? ' (personal vehicle — default)' : '' }}</option>@endforeach</select>
            <p class="hint">Personal vehicle mileage is recorded as an owner contribution.</p></div>
        <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id"><option value="">General business</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected($val('client_id') == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
        <div><label class="label" for="project_id">Project / trip</label><select class="input" id="project_id" name="project_id" data-project-select="#client_id"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}" data-client="{{ $p->client_id }}" @selected($val('project_id') == $p->id)>{{ $p->label() }}</option>@endforeach</select></div>
        <div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="hidden" name="billable" value="0"><input type="checkbox" class="check" name="billable" value="1" @checked($billable)> Billable to the client</label></div>
        <div class="sm:col-span-2"><label class="label" for="notes">Notes</label><textarea class="input" id="notes" name="notes" rows="2">{{ $val('notes') }}</textarea></div>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">{{ $trip ? 'Save trip' : 'Record trip' }}</button><a class="btn" href="{{ $bu }}/mileage">Cancel</a></div>
</form>
@if ($trip)
    <form method="post" action="{{ $bu }}/mileage/{{ $trip->id }}/delete" class="mt-6" data-confirm="Delete this trip? Its expense will be voided with a reversing entry.">@csrf<button class="btn btn-danger btn-sm">Delete trip</button></form>
@endif
@endsection
