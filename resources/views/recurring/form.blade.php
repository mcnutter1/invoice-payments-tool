@extends('layouts.app')
@section('title', $schedule ? 'Edit schedule' : 'New recurring schedule')
@section('content')
@php
    $lines = old('lines', $schedule ? $schedule->lines->map(fn ($l) => ['product_id' => $l->product_id, 'name' => $l->name, 'description' => $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'unit_price' => (string) $l->unit_price, 'tax_rate_id' => $l->tax_rate_id])->all() : [['quantity' => '1']]);
    $lines = array_pad($lines, max(3, count($lines)), []);
    $methods = old('payment_methods', $schedule?->payment_methods ?? $biz->allowedMethods());
@endphp
<h1>{{ $schedule ? 'Edit '.$schedule->name : 'New recurring schedule' }}</h1>
@if ($schedule)<p class="alert alert-info mt-3">Changes apply from the next period on. Invoices already generated are not modified.</p>@endif
<form method="post" action="{{ $schedule ? $bu.'/recurring/'.$schedule->id : $bu.'/recurring' }}" class="mt-4 grid max-w-5xl gap-4">
    @csrf
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" required value="{{ old('name', $schedule?->name) }}" placeholder="Monthly retainer"></div>
        <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id" required>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id', $schedule?->client_id) == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
        <div><label class="label" for="contract_id">Agreement</label><select class="input" id="contract_id" name="contract_id"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" @selected(old('contract_id', $schedule?->contract_id) == $c->id)>{{ $c->number }} · {{ $c->title }} ({{ $c->status }})</option>@endforeach</select></div>
        <div><label class="label" for="cadence">Repeats</label><div class="flex gap-2"><span class="self-center text-sm">every</span><input class="input w-20" type="number" min="1" max="24" name="interval" value="{{ old('interval', $schedule?->interval ?? 1) }}" aria-label="Interval">
            <select class="input" id="cadence" name="cadence">@foreach (['week' => 'week(s)', 'month' => 'month(s)', 'quarter' => 'quarter(s)', 'year' => 'year(s)'] as $k => $v)<option value="{{ $k }}" @selected(old('cadence', $schedule?->cadence ?? 'month') === $k)>{{ $v }}</option>@endforeach</select></div></div>
        <div><label class="label" for="start_date">First invoice date</label><input class="input" type="date" id="start_date" name="start_date" required value="{{ old('start_date', $schedule?->start_date?->toDateString() ?? $biz->localToday()->toDateString()) }}"></div>
        <div><label class="label" for="end_date">End date (optional)</label><input class="input" type="date" id="end_date" name="end_date" value="{{ old('end_date', $schedule?->end_date?->toDateString()) }}"></div>
        <div><label class="label" for="anchor_day">Day of month (optional)</label><input class="input" type="number" min="1" max="31" id="anchor_day" name="anchor_day" value="{{ old('anchor_day', $schedule?->anchor_day) }}"><p class="hint">31 = last day of short months, back to 31 when available.</p></div>
        <div><label class="label" for="terms_days">Payment terms (days)</label><input class="input" type="number" min="0" id="terms_days" name="terms_days" value="{{ old('terms_days', $schedule?->terms_days ?? $biz->default_terms_days) }}"></div>
        <div><label class="label" for="price_policy">Prices</label><select class="input" id="price_policy" name="price_policy"><option value="snapshot" @selected(old('price_policy', $schedule?->price_policy) !== 'catalog')>Use prices below</option><option value="catalog" @selected(old('price_policy', $schedule?->price_policy) === 'catalog')>Use current catalog price</option></select></div>
    </div>
    <div class="card overflow-x-auto"><div class="card-head"><h2>Lines</h2></div>
        <table class="tbl"><thead><tr><th>Item</th><th class="w-24">Qty</th><th class="w-32">Rate ($)</th><th class="w-40">Tax</th></tr></thead><tbody>
        @foreach ($lines as $i => $l)
            <tr>
                <td><select class="input input-sm mb-1" name="lines[{{ $i }}][product_id]" aria-label="Product"><option value="">Ad hoc</option>@foreach ($products as $p)<option value="{{ $p->id }}" @selected(($l['product_id'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach</select>
                    <input class="input input-sm" name="lines[{{ $i }}][name]" value="{{ $l['name'] ?? '' }}" placeholder="Line name" aria-label="Line name">
                    <input class="input input-sm mt-1" name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}" placeholder="Description" aria-label="Description"></td>
                <td><input class="input input-sm" type="number" step="0.0001" min="0" name="lines[{{ $i }}][quantity]" value="{{ $l['quantity'] ?? '' }}" aria-label="Quantity"></td>
                <td><input class="input input-sm" type="number" step="0.0001" min="0" name="lines[{{ $i }}][unit_price]" value="{{ $l['unit_price'] ?? '' }}" aria-label="Rate"></td>
                <td><select class="input input-sm" name="lines[{{ $i }}][tax_rate_id]" aria-label="Tax"><option value="">No tax</option>@foreach ($taxRates as $t)<option value="{{ $t->id }}" @selected(($l['tax_rate_id'] ?? null) == $t->id)>{{ $t->name }}</option>@endforeach</select></td>
            </tr>
        @endforeach
        </tbody></table>
    </div>
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <fieldset class="space-y-2"><legend class="label">Automation</legend>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="auto_issue" value="0"><input class="check" type="checkbox" name="auto_issue" value="1" @checked(old('auto_issue', $schedule?->auto_issue))> Issue automatically (otherwise a draft is created for review)</label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="auto_send" value="0"><input class="check" type="checkbox" name="auto_send" value="1" @checked(old('auto_send', $schedule?->auto_send))> Email automatically after issuing</label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="require_signed_contract" value="0"><input class="check" type="checkbox" name="require_signed_contract" value="1" @checked(old('require_signed_contract', $schedule?->require_signed_contract ?? true))> Require a signed, effective agreement (if linked)</label>
        </fieldset>
        <div class="space-y-2">
            <div><label class="label" for="recipients">Email to</label><input class="input" id="recipients" name="recipients" value="{{ old('recipients', implode(', ', $schedule?->recipients ?? [])) }}" placeholder="Client billing email"></div>
            <fieldset><legend class="label">Payment methods</legend>@foreach ($biz->allowedMethods() as $m)<label class="mr-4 inline-flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="payment_methods[]" value="{{ $m }}" @checked(in_array($m, $methods, true))> {{ $m === 'zelle' ? 'Zelle' : 'Check' }}</label>@endforeach</fieldset>
        </div>
        <div class="sm:col-span-2"><label class="label" for="customer_note">Note on each invoice</label><textarea class="input" id="customer_note" name="customer_note" rows="2">{{ old('customer_note', $schedule?->customer_note) }}</textarea></div>
        @if ($schedule)<div class="sm:col-span-2"><label class="label" for="change_reason">Reason for change (audit)</label><input class="input" id="change_reason" name="change_reason"></div>@endif
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Save schedule</button><a class="btn" href="{{ $bu }}/recurring">Cancel</a></div>
</form>
@endsection
