@extends('layouts.app')
@section('title', $invoice ? 'Edit draft' : 'New invoice')
@section('content')
@php
    $lines = old('lines', $invoice ? $invoice->lines->map(fn ($l) => [
        'product_id' => $l->product_id, 'name' => $l->name, 'description' => $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'),
        'unit_price' => (string) $l->unit_price, 'income_account_id' => $l->income_account_id, 'tax_rate_id' => $l->tax_rate_id, 'contract_milestone_id' => $l->contract_milestone_id,
    ])->all() : [['quantity' => '1']]);
    if (! $lines) $lines = [['quantity' => '1']];
    $methods = old('payment_methods', $invoice?->payment_methods ?? $biz->allowedMethods());
    $action = $invoice ? $bu.'/invoices/'.$invoice->id : $bu.'/invoices';
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>{{ $invoice ? 'Edit draft invoice' : 'New invoice' }}</h1>
    @if ($invoice)
        <div class="flex gap-2">
            <a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}/pdf" target="_blank" rel="noopener">Preview PDF</a>
            <a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}">Review &amp; issue →</a>
        </div>
    @endif
</div>
<form method="post" action="{{ $action }}" class="mt-4 grid gap-4 xl:grid-cols-3" id="invoice-editor" data-invoice-editor>
    @csrf
    @if ($invoice)<input type="hidden" name="lock_version" value="{{ $invoice->lock_version }}">@endif
    <div class="space-y-4 xl:col-span-2">
        <div class="card card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><label class="label" for="client_id">Client</label>
                <select class="input" id="client_id" name="client_id" required data-client-select>
                    <option value="">Choose a client…</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}" data-exempt="{{ $c->tax_exempt ? 1 : 0 }}" data-email="{{ $c->email }}" data-recipients="{{ json_encode($c->recipientOptions()) }}" @selected(old('client_id', $invoice?->client_id ?? $selectedClient) == $c->id)>{{ $c->display_name }}</option>@endforeach
                </select>
                @if ($clients->isEmpty())<p class="hint">No clients yet — <a href="{{ $bu }}/clients/new">add a client</a> first.</p>@endif
            </div>
            <div><label class="label" for="issue_date">Invoice date</label><input class="input" type="date" id="issue_date" name="issue_date" value="{{ old('issue_date', $invoice?->issue_date?->toDateString()) }}"><p class="hint">Blank = the day you issue it.</p></div>
            <div><label class="label" for="due_date">Due date</label><input class="input" type="date" id="due_date" name="due_date" value="{{ old('due_date', $invoice?->due_date?->toDateString()) }}"><p class="hint">Blank = invoice date + client terms.</p></div>
            <div><label class="label" for="service_start">Service period start</label><input class="input" type="date" id="service_start" name="service_start" value="{{ old('service_start', $invoice?->service_start?->toDateString()) }}"></div>
            <div><label class="label" for="service_end">Service period end</label><input class="input" type="date" id="service_end" name="service_end" value="{{ old('service_end', $invoice?->service_end?->toDateString()) }}"></div>
            <div><label class="label" for="po_number">PO / reference</label><input class="input" id="po_number" name="po_number" value="{{ old('po_number', $invoice?->po_number) }}"></div>
            <div><label class="label" for="contract_version_id">Linked agreement</label>
                <select class="input" id="contract_version_id" name="contract_version_id" data-client-filtered="#client_id">
                    <option value="">None</option>
                    @foreach ($contractVersions as $v)<option value="{{ $v->id }}" data-client="{{ $v->contract->client_id }}" @selected(old('contract_version_id', $invoice?->contract_version_id) == $v->id)>{{ $v->contract->number }} v{{ $v->version }} · {{ $v->title }}</option>@endforeach
                </select>
                <p class="hint">Only this client's completed agreements are listed; the client will see that exact signed version.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Line items</h2><span class="text-xs text-slate-500">Tab moves between fields · Alt+Enter adds a line</span></div>
            <div class="overflow-x-auto">
            <table class="tbl" data-lines>
                <thead><tr><th class="min-w-[16rem]">Item</th><th class="w-24">Qty</th><th class="w-32">Rate ($)</th><th class="w-40">Tax</th><th class="num w-28">Amount</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                <tbody>
                @foreach (array_values($lines) as $i => $l)
                    @include('invoices._line', ['i' => $i, 'l' => $l])
                @endforeach
                </tbody>
            </table>
            </div>
            <template id="line-template">@include('invoices._line', ['i' => '__I__', 'l' => ['quantity' => '1']])</template>
            <div class="card-body pt-3"><button type="button" class="btn btn-sm" data-add-line>+ Add line</button></div>
        </div>

        <div class="card card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><label class="label" for="customer_note">Note to client</label><textarea class="input" id="customer_note" name="customer_note" rows="2">{{ old('customer_note', $invoice?->customer_note ?? $biz->default_customer_note) }}</textarea></div>
            <div class="sm:col-span-2"><label class="label" for="terms">Terms</label><textarea class="input" id="terms" name="terms" rows="2">{{ old('terms', $invoice?->terms ?? $biz->default_terms_text) }}</textarea></div>
            <div class="sm:col-span-2"><label class="label" for="internal_notes">Internal notes</label><textarea class="input" id="internal_notes" name="internal_notes" rows="2">{{ old('internal_notes', $invoice?->internal_notes) }}</textarea><p class="hint">Never shown on the PDF or client page.</p></div>
            <fieldset><legend class="label">Payment methods shown</legend>
                @foreach ($biz->allowedMethods() as $m)
                    <label class="mr-4 inline-flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="payment_methods[]" value="{{ $m }}" @checked(in_array($m, $methods, true))> {{ $m === 'zelle' ? 'Zelle' : 'Mail a check' }}</label>
                @endforeach
            </fieldset>
            <div class="sm:col-span-2">
                @php $edClient = $clients->firstWhere('id', (int) old('client_id', $invoice?->client_id ?? $selectedClient)); @endphp
                @include('partials.recipient-picker', ['id' => 'recipients', 'label' => 'Email recipients', 'options' => $edClient?->recipientOptions() ?? [],
                    'selected' => old('recipients', $invoice?->recipients ?? []), 'defaultBilling' => ! $invoice, 'clientSelect' => '#client_id',
                    'hint' => 'Defaults to the billing contact. Each recipient gets their own secure link when you send.'])
            </div>
        </div>
    </div>

    <aside class="space-y-4">
        <div class="card card-body sticky top-20 space-y-3">
            <h2>Summary</h2>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <label class="label col-span-2" for="discount_type">Discount</label>
                <select class="input" id="discount_type" name="discount_type" data-calc>
                    @foreach (['none' => 'None', 'percent' => 'Percent', 'amount' => 'Amount ($)'] as $k => $v)<option value="{{ $k }}" @selected(old('discount_type', $invoice?->discount_type ?? 'none') === $k)>{{ $v }}</option>@endforeach
                </select>
                <input class="input" name="discount_value" type="number" step="0.01" min="0" value="{{ old('discount_value', $invoice ? (float) $invoice->discount_value : '') }}" aria-label="Discount value" data-calc>
            </div>
            <dl class="space-y-1 text-sm" aria-live="polite">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="num" data-out="subtotal">—</dd></div>
                <div class="flex justify-between"><dt>Discount</dt><dd class="num" data-out="discount">—</dd></div>
                <div class="flex justify-between"><dt>Tax</dt><dd class="num" data-out="tax">—</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd class="num" data-out="total">—</dd></div>
            </dl>
            <p class="hint">Live estimate. Final amounts are calculated on the server with half-up rounding per line, proportional discount allocation and exclusive tax on discounted lines.</p>
            @if ($invoice)
                <details class="text-xs text-slate-600"><summary class="cursor-pointer">Saved calculation details</summary>
                    <table class="mt-2 w-full">
                        @foreach ($invoice->lines as $l)
                            <tr><td class="pr-2">{{ $l->name }}</td><td class="num">{{ \App\Support\Money::format($l->amount_cents) }} − {{ \App\Support\Money::format($l->discount_cents) }} + tax {{ \App\Support\Money::format($l->tax_cents) }}</td></tr>
                        @endforeach
                        <tr><td class="pt-1 font-semibold">Saved total</td><td class="num pt-1 font-semibold">{{ \App\Support\Money::format($invoice->total_cents) }}</td></tr>
                    </table>
                </details>
            @endif
            <div class="flex flex-col gap-2">
                <button class="btn btn-primary" name="next" value="review">Save &amp; review</button>
                <button class="btn" name="next" value="stay">Save draft</button>
                @if ($invoice)
                    <a class="btn btn-ghost" href="{{ $bu }}/invoices/{{ $invoice->id }}/pdf" target="_blank" rel="noopener">Open PDF preview</a>
                @endif
            </div>
            <p class="text-xs text-slate-500" data-dirty-note hidden>Unsaved changes.</p>
        </div>
    </aside>
</form>
<script type="application/json" id="catalog-data">{!! json_encode($products->mapWithKeys(fn ($p) => [$p->id => ['name' => $p->name, 'description' => $p->description, 'price' => (string) $p->default_price, 'tax_rate_id' => $p->tax_rate_id, 'income_account_id' => $p->income_account_id]]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script type="application/json" id="tax-data">{!! json_encode($taxRates->mapWithKeys(fn ($t) => [$t->id => (string) $t->rate]), JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endsection
