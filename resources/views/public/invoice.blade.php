@extends('layouts.public', ['accent' => $accent])
@section('title', 'Invoice '.$invoice->number.' from '.($business['name'] ?? ''))
@section('content')
@php $M = fn ($c) => \App\Support\Money::format((int) $c); $memo = 'Invoice '.$invoice->number; @endphp
<article class="card">
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 p-5">
        <div class="flex items-center gap-3">
            @if ($logo)<img src="{{ $logo }}" alt="{{ $business['name'] }} logo" class="h-12 max-w-[10rem] object-contain">@endif
            <div><div class="text-lg font-semibold text-slate-900">{{ $business['name'] }}</div>
                <div class="text-xs text-slate-500">{{ implode(' · ', array_filter([$business['email'] ?? null, $business['phone'] ?? null])) }}</div></div>
        </div>
        <div class="text-right"><div class="text-sm text-slate-500">Invoice</div><div class="font-mono text-lg font-semibold">{{ $invoice->number }}</div></div>
    </header>
    <section class="grid gap-4 p-5 sm:grid-cols-3">
        <div class="sm:col-span-2">
            @if ($invoice->status === 'void')
                <p class="alert alert-warn">This invoice was voided by {{ $business['name'] }} and is no longer payable.</p>
            @elseif ($invoice->balance_cents <= 0)
                <div class="text-sm text-slate-500">Status</div><div class="text-2xl font-semibold text-emerald-700">Paid in full</div>
                <p class="text-sm text-slate-600">Thank you! No payment is due.</p>
            @else
                <div class="text-sm text-slate-500">{{ $invoice->paid_cents > 0 ? 'Remaining balance' : 'Amount due' }}</div>
                <div class="text-3xl font-semibold tabular-nums text-slate-900">{{ $M($invoice->balance_cents) }}</div>
                <div class="text-sm {{ $invoice->isOverdue() ? 'font-semibold text-red-700' : 'text-slate-600' }}">Due {{ $invoice->due_date?->format('F j, Y') }}{{ $invoice->isOverdue() ? ' · past due' : '' }}</div>
                @if ($invoice->paid_cents > 0)<p class="mt-1 text-sm text-slate-600">{{ $M($invoice->paid_cents) }} received of {{ $M($invoice->total_cents) }}.</p>@endif
            @endif
            @if ($openClaim)
                <p class="alert alert-info mt-3"><strong>Payment reported — awaiting confirmation.</strong> You reported a {{ $openClaim->method === 'zelle' ? 'Zelle' : 'check' }} payment of {{ $M($openClaim->reported_amount_cents) }} on {{ $openClaim->reported_date->format('M j') }}. {{ $business['name'] }} will confirm once it arrives. This page will show it as paid only after confirmation.</p>
            @endif
        </div>
        <div class="text-sm">
            <div class="text-slate-500">Billed to</div>
            <div class="font-medium">{{ $client['display_name'] ?? '' }}</div>
            @foreach ($client['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
            <div class="mt-2 text-slate-500">Issued {{ $invoice->issue_date?->format('M j, Y') }}</div>
            @if ($invoice->po_number)<div class="text-slate-500">PO {{ $invoice->po_number }}</div>@endif
        </div>
    </section>
    <div class="flex flex-wrap gap-2 px-5 pb-4">
        <a class="btn" href="/i/v/{{ $invoice->ulid }}/pdf">Download PDF</a>
        @if (! empty($instructions['check']) && $invoice->balance_cents > 0)<a class="btn" href="/i/v/{{ $invoice->ulid }}/remittance">Remittance slip</a>@endif
        @if ($agreement)<a class="btn" href="/i/v/{{ $invoice->ulid }}/agreement">Signed agreement</a>@if($agreement->certificate_document_id)<a class="btn btn-ghost" href="/i/v/{{ $invoice->ulid }}/agreement/certificate">Signature certificate</a>@endif @endif
    </div>
    <div class="overflow-x-auto border-t border-slate-100">
        <table class="tbl">
            <thead><tr><th>Description</th>@if($template['show_quantity'] ?? true)<th class="num">Qty</th><th class="num">Rate</th>@endif<th class="num">Amount</th></tr></thead>
            <tbody>
            @foreach ($lines as $l)
                <tr><td><div class="font-medium">{{ $l->name }}</div>@if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif</td>
                    @if($template['show_quantity'] ?? true)<td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}</td><td class="num">${{ number_format((float) $l->unit_price, 2) }}</td>@endif
                    <td class="num">{{ $M($l->amount_cents) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <dl class="ml-auto max-w-xs space-y-1 p-5 text-sm">
            <div class="flex justify-between"><dt>Subtotal</dt><dd class="num">{{ $M($invoice->subtotal_cents) }}</dd></div>
            @if ($invoice->discount_cents)<div class="flex justify-between"><dt>Discount</dt><dd class="num">−{{ $M($invoice->discount_cents) }}</dd></div>@endif
            @foreach ($tax_lines as $t)<div class="flex justify-between"><dt>{{ $t->name }}</dt><dd class="num">{{ $M($t->tax_cents) }}</dd></div>@endforeach
            <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><dt>Total</dt><dd class="num">{{ $M($invoice->total_cents) }}</dd></div>
            @if ($invoice->paid_cents)<div class="flex justify-between"><dt>Paid</dt><dd class="num">−{{ $M($invoice->paid_cents) }}</dd></div>@endif
            @if ($invoice->credited_cents + $invoice->written_off_cents)<div class="flex justify-between"><dt>Credits</dt><dd class="num">−{{ $M($invoice->credited_cents + $invoice->written_off_cents) }}</dd></div>@endif
            <div class="flex justify-between font-semibold"><dt>Balance</dt><dd class="num">{{ $M(max(0, $invoice->balance_cents)) }}</dd></div>
        </dl>
    </div>
    @if ($invoice->customer_note || $invoice->terms)
        <div class="border-t border-slate-100 p-5 text-sm text-slate-600">
            @if ($invoice->customer_note)<p class="whitespace-pre-line">{{ $invoice->customer_note }}</p>@endif
            @if ($invoice->terms)<p class="mt-2 whitespace-pre-line text-xs">{{ $invoice->terms }}</p>@endif
        </div>
    @endif
</article>

@if ($invoice->status === 'issued' && $invoice->balance_cents > 0)
<section class="mt-6 space-y-4" aria-labelledby="howtopay">
    <h2 id="howtopay">How to pay</h2>
    @if (! empty($instructions['zelle']))
        @php $z = $instructions['zelle']; @endphp
        <div class="card card-body">
            <h3>Zelle</h3>
            <p class="mt-1 text-sm text-slate-600">Open your bank's app or website and send with Zelle. {{ $business['name'] }} does not see or collect your bank details.</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2"><dt class="text-slate-500">Send to</dt><dd class="font-medium">{{ $z['recipient_name'] }}</dd></div>
                @foreach (array_filter(['Email' => $z['email'] ?? null, 'Mobile' => $z['phone'] ?? null]) as $k => $v)
                    <div class="flex flex-wrap items-center justify-between gap-2"><dt class="text-slate-500">{{ $k }}</dt><dd><span class="font-mono">{{ $v }}</span> <button type="button" class="btn btn-sm" data-copy="{{ $v }}">Copy</button></dd></div>
                @endforeach
                <div class="flex flex-wrap items-center justify-between gap-2"><dt class="text-slate-500">Amount</dt><dd><span class="font-mono">{{ number_format($invoice->balance_cents / 100, 2, '.', '') }}</span> <button type="button" class="btn btn-sm" data-copy="{{ number_format($invoice->balance_cents / 100, 2, '.', '') }}">Copy</button></dd></div>
                <div class="flex flex-wrap items-center justify-between gap-2"><dt class="text-slate-500">Memo</dt><dd><span class="font-mono">{{ $memo }}</span> <button type="button" class="btn btn-sm" data-copy="{{ $memo }}">Copy</button></dd></div>
            </dl>
            @if ($zelleQr)<div class="mt-3"><p class="text-xs text-slate-500">Scan with your bank's Zelle app:</p><img src="{{ $zelleQr }}" alt="Zelle QR code provided by {{ $business['name'] }}'s bank" class="mt-1 h-40 w-40"></div>@endif
        </div>
    @endif
    @if (! empty($instructions['check']))
        @php $c = $instructions['check']; @endphp
        <div class="card card-body">
            <h3>Mail a check</h3>
            <dl class="mt-2 space-y-1 text-sm">
                <div><dt class="inline text-slate-500">Payable to:</dt> <dd class="inline font-medium">{{ $c['payee'] }}</dd></div>
                <div><dt class="text-slate-500">Mail to:</dt><dd class="whitespace-pre-line">{{ $c['address'] }}</dd></div>
                <div><dt class="inline text-slate-500">Memo:</dt> <dd class="inline font-mono">{{ $memo }}</dd></div>
            </dl>
        </div>
    @endif
    @if ($canReport)
    <details class="card" @if($errors->any()) open @endif>
        <summary class="card-head cursor-pointer"><h3>I sent payment</h3><span class="text-xs text-slate-500">Let {{ $business['name'] }} know so they can look for it</span></summary>
        <form method="post" action="/i/v/{{ $invoice->ulid }}/report-payment" enctype="multipart/form-data" class="card-body grid gap-3 sm:grid-cols-2">
            @csrf
            <div><label class="label" for="method">Method</label><select class="input" id="method" name="method">@foreach ($instructions['methods'] ?? [] as $m)<option value="{{ $m }}">{{ $m === 'zelle' ? 'Zelle' : 'Mailed check' }}</option>@endforeach</select></div>
            <div><label class="label" for="amount">Amount sent ($)</label><input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount', number_format($invoice->balance_cents / 100, 2, '.', '')) }}"></div>
            <div><label class="label" for="reported_date">Date sent or mailed</label><input class="input" id="reported_date" name="reported_date" type="date" required value="{{ old('reported_date', now()->toDateString()) }}"></div>
            <div><label class="label" for="check_number">Check number (if check)</label><input class="input" id="check_number" name="check_number" value="{{ old('check_number') }}"></div>
            <div class="sm:col-span-2"><label class="label" for="reference">Confirmation / reference (optional)</label><input class="input" id="reference" name="reference" value="{{ old('reference') }}"></div>
            <div class="sm:col-span-2"><label class="label" for="note">Note (optional)</label><textarea class="input" id="note" name="note" rows="2">{{ old('note') }}</textarea></div>
            <div class="sm:col-span-2"><label class="label" for="receipt">Receipt screenshot or PDF (optional)</label><input class="input" id="receipt" name="receipt" type="file" accept="application/pdf,image/png,image/jpeg"><p class="hint">Do not include bank account or routing numbers.</p></div>
            <div class="sm:col-span-2"><button class="btn btn-primary">Report payment</button><p class="hint mt-1">This notifies {{ $business['name'] }}. It is not a payment and does not mark the invoice paid until they confirm receipt.</p></div>
        </form>
    </details>
    @endif
</section>
@endif
@if ($claims->whereIn('status', ['confirmed'])->isNotEmpty())
    <p class="mt-4 text-sm text-slate-600">Confirmed payments are reflected in the balance above.</p>
@endif
@endsection
