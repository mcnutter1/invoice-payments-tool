@extends('layouts.app')
@section('title', $schedule ? 'Edit schedule' : 'New recurring schedule')
@section('content')
@php
    $lines = old('lines', $schedule ? $schedule->lines->map(fn ($l) => ['product_id' => $l->product_id, 'name' => $l->name, 'description' => $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'unit_price' => (string) $l->unit_price, 'tax_rate_id' => $l->tax_rate_id])->all() : []);
    $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['name']) || ! empty($l['product_id'])));
    $methods = old('payment_methods', $schedule?->payment_methods ?? $biz->allowedMethods());
    $clientId = (int) old('client_id', $schedule?->client_id ?? (request('client') ?: $clients->first()?->id));
    $curClient = $clients->firstWhere('id', $clientId);
@endphp
<h1>{{ $schedule ? 'Edit '.$schedule->name : 'New recurring schedule' }}</h1>
@if ($schedule)<p class="alert alert-info mt-3">Changes apply from the next period on. Invoices already generated are not modified.</p>@endif
<form method="post" action="{{ $schedule ? $bu.'/recurring/'.$schedule->id : $bu.'/recurring' }}" class="mt-4 grid max-w-5xl gap-4" data-recurring-form>
    @csrf
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" required value="{{ old('name', $schedule?->name) }}" placeholder="Monthly retainer"></div>
        <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id" required>@foreach ($clients as $c)<option value="{{ $c->id }}" data-recipients="{{ json_encode($c->recipientOptions()) }}" @selected($clientId === $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
        <div><label class="label" for="contract_id">Agreement</label><select class="input" id="contract_id" name="contract_id" data-client-filtered="#client_id"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" data-client="{{ $c->client_id }}" @selected(old('contract_id', $schedule?->contract_id) == $c->id) @if((int) $c->client_id !== $clientId) hidden disabled @endif>{{ $c->number }} · {{ $c->title }} ({{ $c->status }})</option>@endforeach</select>
            <p class="hint">Only the selected client's agreements are listed.</p></div>
        <div><label class="label" for="cadence">Repeats</label><div class="flex gap-2"><span class="self-center text-sm">every</span><input class="input w-20" type="number" min="1" max="24" name="interval" value="{{ old('interval', $schedule?->interval ?? 1) }}" aria-label="Interval" data-interval>
            <select class="input" id="cadence" name="cadence" data-cadence>@foreach (['week' => 'week(s)', 'month' => 'month(s)', 'quarter' => 'quarter(s)', 'year' => 'year(s)'] as $k => $v)<option value="{{ $k }}" @selected(old('cadence', $schedule?->cadence ?? 'month') === $k)>{{ $v }}</option>@endforeach</select></div></div>
        <div><label class="label" for="start_date">First invoice date</label><input class="input" type="date" id="start_date" name="start_date" required value="{{ old('start_date', $schedule?->start_date?->toDateString() ?? $biz->localToday()->toDateString()) }}"></div>
        <div><label class="label" for="end_date">End date (optional)</label><input class="input" type="date" id="end_date" name="end_date" value="{{ old('end_date', $schedule?->end_date?->toDateString()) }}"></div>
        <div><label class="label" for="anchor_day">Day of month (optional)</label><input class="input" type="number" min="1" max="31" id="anchor_day" name="anchor_day" value="{{ old('anchor_day', $schedule?->anchor_day) }}"><p class="hint">31 = last day of short months, back to 31 when available.</p></div>
        <div><label class="label" for="terms_days">Payment terms (days)</label><input class="input" type="number" min="0" id="terms_days" name="terms_days" value="{{ old('terms_days', $schedule?->terms_days ?? $biz->default_terms_days) }}"></div>
        <div><label class="label" for="price_policy">Prices</label><select class="input" id="price_policy" name="price_policy"><option value="snapshot" @selected(old('price_policy', $schedule?->price_policy) !== 'catalog')>Use prices below</option><option value="catalog" @selected(old('price_policy', $schedule?->price_policy) === 'catalog')>Use current catalog price</option></select></div>
    </div>
    <div class="card">
        <div class="card-head"><div><h2>Services &amp; products</h2><p class="text-xs text-slate-500">Billed on every invoice in this schedule.</p></div></div>
        <div class="overflow-x-auto" @if(! $lines) hidden @endif data-lines-wrap>
            <table class="tbl" data-lines data-allow-empty>
                <thead><tr><th>Item</th><th class="w-24">Qty</th><th class="w-32">Rate ($)</th><th class="w-40">Tax</th><th class="num w-28">Amount</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                <tbody>
                @foreach ($lines as $i => $l)
                    @include('recurring._line', ['i' => $i, 'l' => $l])
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="m-4 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500" @if($lines) hidden @endif data-lines-empty>
            No items yet. Add the services or products to bill each period.
        </div>
        <template id="line-template">@include('recurring._line', ['i' => '__I__', 'l' => ['quantity' => '1']])</template>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 sm:px-5">
            <button type="button" class="btn" data-add-line>+ Add service or product</button>
            <dl class="flex gap-6 text-sm">
                <div><dt class="text-xs text-slate-500">Per invoice (before tax)</dt><dd class="text-right font-semibold tabular-nums" data-out="period">$0.00</dd></div>
                <div><dt class="text-xs text-slate-500">Annual value</dt><dd class="text-right font-semibold tabular-nums" data-out="annual">$0.00</dd></div>
            </dl>
        </div>
    </div>
<script type="application/json" id="catalog-data">{!! json_encode($products->mapWithKeys(fn ($p) => [$p->id => ['name' => $p->name, 'description' => $p->description, 'price' => (string) $p->default_price, 'tax_rate_id' => $p->tax_rate_id]]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <fieldset class="space-y-2"><legend class="label">Automation</legend>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="auto_issue" value="0"><input class="check" type="checkbox" name="auto_issue" value="1" @checked(old('auto_issue', $schedule?->auto_issue))> Issue automatically (otherwise a draft is created for review)</label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="auto_send" value="0"><input class="check" type="checkbox" name="auto_send" value="1" @checked(old('auto_send', $schedule?->auto_send))> Email automatically after issuing</label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="require_signed_contract" value="0"><input class="check" type="checkbox" name="require_signed_contract" value="1" @checked(old('require_signed_contract', $schedule?->require_signed_contract ?? true))> Require a signed, effective agreement (if linked)</label>
        </fieldset>
        <div class="space-y-2">
            @include('partials.recipient-picker', ['id' => 'recipients', 'options' => $curClient?->recipientOptions() ?? [], 'selected' => old('recipients', $schedule?->recipients ?? []), 'defaultBilling' => ! $schedule, 'clientSelect' => '#client_id', 'hint' => 'Used when invoices are emailed automatically. Each recipient gets their own secure link.'])
            <fieldset><legend class="label">Payment methods</legend>@foreach ($biz->allowedMethods() as $m)<label class="mr-4 inline-flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="payment_methods[]" value="{{ $m }}" @checked(in_array($m, $methods, true))> {{ $m === 'zelle' ? 'Zelle' : 'Check' }}</label>@endforeach</fieldset>
        </div>
        <div class="sm:col-span-2"><label class="label" for="customer_note">Note on each invoice</label><textarea class="input" id="customer_note" name="customer_note" rows="2">{{ old('customer_note', $schedule?->customer_note) }}</textarea></div>
        @if ($schedule)<div class="sm:col-span-2"><label class="label" for="change_reason">Reason for change (audit)</label><input class="input" id="change_reason" name="change_reason"></div>@endif
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Save schedule</button><a class="btn" href="{{ $bu }}/recurring">Cancel</a></div>
</form>
@endsection
