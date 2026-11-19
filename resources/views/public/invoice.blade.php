@extends('layouts.public', ['accent' => $accent, 'wide' => true])
@section('title', 'Invoice '.$invoice->number.' from '.($business['name'] ?? ''))
@section('content')
@php
    $preview = $preview ?? false;
    $M = fn ($c) => \App\Support\Money::format((int) $c);
    $memo = $invoice->number ? 'Invoice '.$invoice->number : 'Invoice number';
    $payable = ($invoice->status === 'issued' || ($preview && $invoice->isDraft())) && $invoice->balance_cents > 0;
    [$statusLabel, $statusClass] = match (true) {
        $invoice->status === 'void' => ['Cancelled', 'chip-gray'],
        $invoice->balance_cents <= 0 => ['Paid', 'chip-green'],
        (bool) $openClaim => ['Payment reported', 'chip-blue'],
        $invoice->isOverdue() => ['Past due', 'chip-red'],
        default => ['Due', 'chip-amber'],
    };
    $amountValue = number_format($invoice->balance_cents / 100, 2, '.', '');
    $canReport = $canReport ?? false;
    $reportMethods = $reportMethods ?? array_values(array_intersect($instructions['methods'] ?? [], \App\Support\PaymentMethods::MANUAL));
    $autopayOffer = $autopayOffer ?? false;
    $autopay = $autopay ?? null;
@endphp

@if ($preview)
<div class="alert alert-info mb-4 flex flex-wrap items-center justify-between gap-2">
    <span>Preview — this is what {{ $invoice->client?->display_name ?? 'the client' }} sees{{ $invoice->isDraft() ? '. Dates and the invoice number are filled in when you issue it.' : '.' }}</span>
    <span class="flex gap-2"><a class="btn btn-sm" href="{{ $pdfUrl }}" target="_blank" rel="noopener">PDF</a><a class="btn btn-sm" href="{{ $backUrl }}">Back to invoice</a></span>
</div>
@endif

@include('public._invoice-sheet')

@if ($payable)
@php
    $track = $preview ? '' : '/i/v/'.$invoice->ulid.'/event';
    $options = array_values(array_filter(\App\Support\PaymentMethods::INVOICE, fn ($m) => ! empty($instructions[$m])));
    $ach = $instructions['ach'] ?? null;
    $meta = [
        'card' => ['Pay by card', 'Visa, Mastercard, Amex, Discover', 'Instant · confirmed automatically'],
        'ach' => ['Bank transfer (ACH / wire)', $ach && ! empty($ach['bank_name']) ? 'To our account at '.$ach['bank_name'] : 'Bill pay or wire from your bank', '1–3 business days · no fees'],
        'zelle' => ['Zelle', 'From your bank’s app or website', 'Usually within minutes'],
        'venmo' => ['Venmo', ! empty($instructions['venmo']['handle']) ? '@'.$instructions['venmo']['handle'] : 'From the Venmo app', 'Usually within minutes'],
        'check' => ['Mail a check', 'Payable to '.($instructions['check']['payee'] ?? ''), 'Allow time for mail'],
    ];
    $openFirst = in_array(request('method'), $options, true) ? request('method') : ($options[0] ?? null);
    $sentLink = fn (string $m) => $canReport && in_array($m, $reportMethods, true) && ! $preview;
    $hasManual = ($canReport || $preview) && array_intersect($options, \App\Support\PaymentMethods::MANUAL);
@endphp
<section class="pay mt-8 scroll-mt-4 space-y-5" id="howtopay" aria-labelledby="howtopay-h" style="--accent: {{ $accent }}">
    <div>
        <h2 id="howtopay-h" class="text-xl">How to pay</h2>
        <p class="text-sm text-slate-500">{{ $M($invoice->balance_cents) }} due {{ $invoice->due_date?->format('F j, Y') ?? 'on receipt' }} · reference <span class="font-mono text-slate-700">{{ $memo }}</span></p>
    </div>

    <ol @class(['grid gap-3 text-sm', 'sm:grid-cols-3' => $hasManual, 'sm:grid-cols-2' => ! $hasManual])>
        <li class="flex gap-3"><span class="pay-step-n">1</span><span><strong class="block text-slate-900">Choose a method</strong><span class="text-slate-600">Pick whichever is easiest for you.</span></span></li>
        <li class="flex gap-3"><span class="pay-step-n">2</span><span><strong class="block text-slate-900">Send {{ $M($invoice->balance_cents) }}</strong><span class="text-slate-600">Include <span class="font-mono">{{ $memo }}</span> so we can match it.</span></span></li>
        @if ($hasManual)
            <li class="flex gap-3"><span class="pay-step-n">3</span><span><strong class="block text-slate-900">Let us know it’s sent</strong><span class="text-slate-600">{{ in_array('card', $options, true) ? 'Not needed for card — that’s confirmed automatically.' : 'We’ll confirm and email you a receipt.' }}</span></span></li>
        @endif
    </ol>

    <div class="pay-picker space-y-4">
    <fieldset @class(['hidden' => count($options) === 1])>
        <legend class="sr-only">Payment method</legend>
        <div @class(['grid gap-3', 'sm:grid-cols-2' => count($options) > 1, 'lg:grid-cols-3' => count($options) > 2])>
        @foreach ($options as $m)
            @php [$title, $blurb, $timing] = $meta[$m]; @endphp
            <label class="pay-tile">
                <input class="sr-only" type="radio" name="paymethod" value="{{ $m }}" @checked($m === $openFirst)>
                <span class="pay-radio" aria-hidden="true"></span>
                <span class="min-w-0">
                    <span class="block font-semibold text-slate-900">{{ $title }}</span>
                    <span class="block truncate text-sm text-slate-500">{{ $blurb }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ $timing }}</span>
                </span>
            </label>
        @endforeach
        </div>
    </fieldset>

    @foreach ($options as $m)
        <div class="pay-panel card scroll-mt-4 overflow-hidden" data-method="{{ $m }}" id="pay-{{ $m }}" role="region" aria-label="{{ $meta[$m][0] }}">
            <div class="card-body">
            <h3 class="mb-3">{{ $m === 'card' ? 'Pay securely by card' : 'Send '.$M($invoice->balance_cents).' by '.\App\Support\PaymentMethods::label($m) }}</h3>

            @if ($m === 'card')
                @if ($autopay)
                    <p class="alert alert-info mb-3">Autopay is on: {{ $autopay->autopay_card_label }} is charged automatically for this billing schedule. If it didn’t go through, you can pay below.</p>
                @endif
                <form method="post" action="/i/v/{{ $invoice->ulid }}/card" class="space-y-3">@csrf
                    @if ($autopayOffer)
                        <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                            <input class="check mt-0.5" type="checkbox" name="save_card" value="1">
                            <span><strong>Save this card for automatic payments</strong><br><span class="text-slate-600">Future invoices for “{{ $invoice->schedule?->name }}” will be charged to this card when they’re issued, and you’ll get a receipt each time. You can ask {{ $business['name'] }} to stop at any time.</span></span>
                        </label>
                    @endif
                    <button class="btn btn-primary btn-lg w-full sm:w-auto" @disabled($preview)>Pay {{ $M($invoice->balance_cents) }} by card</button>
                    <p class="hint flex items-center gap-1"><svg aria-hidden="true" viewBox="0 0 20 20" class="h-3.5 w-3.5 fill-current"><path d="M10 2a4 4 0 0 0-4 4v2H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V6a4 4 0 0 0-4-4Zm-2 6V6a2 2 0 1 1 4 0v2H8Z"/></svg> You’ll finish on Stripe’s secure checkout. Card details never touch this site. The invoice is marked paid immediately.</p>
                </form>

            @elseif ($m === 'ach')
                <div class="grid gap-4 md:grid-cols-5">
                    <dl class="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm md:col-span-3">
                        <div class="flex items-center justify-between gap-3 px-3 py-2"><dt class="text-slate-500">Bank</dt><dd class="text-right"><span class="font-medium">{{ $ach['bank_name'] ?: '—' }}</span>@if(! empty($ach['bank_location']))<span class="block text-xs text-slate-500">{{ $ach['bank_location'] }}</span>@endif</dd></div>
                        @include('public._copy-row', ['label' => 'Account name', 'value' => $ach['account_name'], 'mono' => false, 'track' => 'ach_name', 'trackUrl' => $track])
                        @include('public._copy-row', ['label' => 'Routing (ACH)', 'value' => $ach['routing_number'], 'track' => 'ach_routing', 'trackUrl' => $track])
                        @if (! empty($ach['wire_routing_number']) && $ach['wire_routing_number'] !== $ach['routing_number'])
                            @include('public._copy-row', ['label' => 'Routing (wire)', 'value' => $ach['wire_routing_number'], 'track' => 'ach_wire_routing', 'trackUrl' => $track])
                        @endif
                        @include('public._copy-row', ['label' => 'Account number', 'value' => $ach['account_number'] ?? '', 'track' => 'ach_account', 'trackUrl' => $track])
                        <div class="flex items-center justify-between gap-3 px-3 py-2"><dt class="text-slate-500">Account type</dt><dd>{{ ucfirst($ach['account_type'] ?: 'checking') }}</dd></div>
                        @if (! empty($ach['swift_code']))
                            @include('public._copy-row', ['label' => 'SWIFT / BIC', 'value' => $ach['swift_code'], 'track' => 'ach_swift', 'trackUrl' => $track])
                        @endif
                        @if (! empty($ach['bank_address']))<div class="flex items-center justify-between gap-3 px-3 py-2"><dt class="text-slate-500">Bank address</dt><dd class="text-right">{{ $ach['bank_address'] }}</dd></div>@endif
                        @include('public._copy-row', ['label' => 'Amount', 'value' => $amountValue, 'track' => 'amount', 'trackUrl' => $track])
                        @include('public._copy-row', ['label' => 'Reference', 'value' => $memo, 'track' => 'memo', 'trackUrl' => $track])
                    </dl>
                    <div class="space-y-3 text-sm text-slate-600 md:col-span-2">
                        <p><strong class="text-slate-800">ACH</strong> (bill pay, “send to another bank”) usually arrives in 1–3 business days. <strong class="text-slate-800">Wires</strong> arrive the same day; your bank may charge a fee.</p>
                        @if (! empty($ach['instructions']))<p>{{ $ach['instructions'] }}</p>@endif
                        <p>Please include <strong class="font-mono text-slate-800">{{ $memo }}</strong> in the payment reference so we can match it.</p>
                        <p class="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">Protect yourself from fraud: {{ $business['name'] }} will never email you new bank details. If you receive a message saying the account changed, call us before sending money.</p>
                    </div>
                </div>

            @elseif ($m === 'zelle')
                @php $z = $instructions['zelle']; @endphp
                @if ($zelleQr)
                    <figure class="flex flex-col items-center text-center">
                        <img src="{{ $zelleQr }}" alt="Zelle QR code for {{ $z['recipient_name'] }}" class="h-44 w-44 rounded-lg border border-slate-200 bg-white object-contain p-2">
                        <figcaption class="mt-2 text-xs text-slate-500">Scan with your bank's Zelle app</figcaption>
                    </figure>
                @endif
                {{-- With a QR code the typed details are optional, so they start collapsed. --}}
                <details class="group/z mt-4" @if(! $zelleQr) open @endif>
                    <summary class="btn w-full cursor-pointer list-none justify-center @if(! $zelleQr) hidden @endif"><span class="group-open/z:hidden">Show Zelle details</span><span class="hidden group-open/z:inline">Hide Zelle details</span></summary>
                    <dl class="mt-4 divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
                        <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Send to</dt><dd class="text-right font-medium">{{ $z['recipient_name'] }}</dd></div>
                        @foreach (array_filter(['Email' => $z['email'] ?? null, 'Mobile' => $z['phone'] ?? null]) as $k => $v)
                            @include('public._copy-row', ['label' => $k, 'value' => $v, 'track' => 'zelle_'.strtolower($k), 'trackUrl' => $track])
                        @endforeach
                        @include('public._copy-row', ['label' => 'Amount', 'value' => $amountValue, 'track' => 'amount', 'trackUrl' => $track])
                        @include('public._copy-row', ['label' => 'Memo', 'value' => $memo, 'track' => 'memo', 'trackUrl' => $track])
                    </dl>
                </details>

            @elseif ($m === 'venmo')
                @php $vh = $instructions['venmo']['handle']; $venmoUrl = 'https://venmo.com/'.rawurlencode($vh).'?'.http_build_query(['txn' => 'pay', 'amount' => $amountValue, 'note' => $memo], '', '&', PHP_QUERY_RFC3986); @endphp
                <div class="grid gap-4 md:grid-cols-5">
                    <dl class="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm md:col-span-3">
                        @include('public._copy-row', ['label' => 'Venmo', 'value' => '@'.$vh, 'track' => 'venmo_handle', 'trackUrl' => $track])
                        @include('public._copy-row', ['label' => 'Amount', 'value' => $amountValue, 'track' => 'amount', 'trackUrl' => $track])
                        @include('public._copy-row', ['label' => 'Note', 'value' => $memo, 'track' => 'memo', 'trackUrl' => $track])
                    </dl>
                    <div class="space-y-3 text-sm text-slate-600 md:col-span-2">
                        @unless ($preview)<a class="btn btn-primary w-full justify-center" href="{{ $venmoUrl }}" target="_blank" rel="noopener noreferrer">Open Venmo to pay {{ $M($invoice->balance_cents) }}</a>@endunless
                        <p>Pay <strong>{{ '@'.$vh }}</strong> and keep <strong class="font-mono text-slate-800">{{ $memo }}</strong> in the note — that’s how we match your payment.</p>
                    </div>
                </div>

            @elseif ($m === 'check')
                @php $c = $instructions['check']; @endphp
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Payable to</dt><dd class="font-medium">{{ $c['payee'] }}</dd></div>
                    <div><dt class="text-slate-500">Mail to</dt><dd class="whitespace-pre-line">{{ $c['address'] }}</dd></div>
                    <div><dt class="text-slate-500">Memo</dt><dd class="font-mono">{{ $memo }}</dd></div>
                </dl>
                @unless ($preview)<a class="btn btn-sm mt-4" href="/i/v/{{ $invoice->ulid }}/remittance">Print remittance slip</a>@endunless
            @endif
            </div>

            @if ($m === 'card')
                <div class="pay-next"><p class="text-sm text-slate-600"><strong class="text-slate-900">That’s it.</strong> Card payments are recorded instantly and you’ll get a receipt by email.</p></div>
            @elseif ($sentLink($m))
                <div class="pay-next">
                    <p class="text-sm text-slate-600"><strong class="block text-slate-900">Sent it? Let {{ $business['name'] }} know.</strong>It takes a few seconds and helps us match your payment quickly.</p>
                    <a class="btn btn-primary w-full justify-center sm:w-auto" href="/i/v/{{ $invoice->ulid }}?report=1&amp;method={{ $m }}#report" data-report-method="{{ $m }}">I’ve sent this payment</a>
                </div>
            @endif
        </div>
    @endforeach
    </div>

    @if ($canReport)
    <details id="report" class="card group scroll-mt-4 overflow-hidden" @if($errors->any() || ! empty($openReport)) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-4 sm:px-5">
            <span class="flex items-center gap-3">
                <svg aria-hidden="true" viewBox="0 0 20 20" class="h-6 w-6 shrink-0 fill-current text-emerald-600"><path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3-4 4a1 1 0 0 1-1.4 0l-2-2a1 1 0 1 1 1.4-1.4L9 10.6l3.3-3.3a1 1 0 0 1 1.4 1.4Z"/></svg>
                <span><span class="block font-semibold text-slate-900">Already paid? Tell {{ $business['name'] }}</span><span class="block text-sm text-slate-500">For bank transfer, Zelle, Venmo or check payments</span></span>
            </span>
            <svg aria-hidden="true" viewBox="0 0 20 20" class="h-5 w-5 shrink-0 fill-current text-slate-400 transition-transform group-open:rotate-180"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4Z"/></svg>
        </summary>
        <form method="post" action="/i/v/{{ $invoice->ulid }}/report-payment" enctype="multipart/form-data" class="grid gap-4 border-t border-slate-100 px-4 py-5 sm:grid-cols-2 sm:px-5">
            @csrf
            <div><label class="label" for="method">How did you pay?</label><select class="input" id="method" name="method">@foreach ($reportMethods as $m)<option value="{{ $m }}" @selected(old('method', $reportMethod ?? null) === $m)>{{ \App\Support\PaymentMethods::label($m) }}</option>@endforeach</select></div>
            <div><label class="label" for="amount">Amount sent ($)</label><input class="input" id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" required value="{{ old('amount', $amountValue) }}"></div>
            <div><label class="label" for="reported_date">Date sent or mailed</label><input class="input" id="reported_date" name="reported_date" type="date" required value="{{ old('reported_date', now()->toDateString()) }}"></div>
            <div><label class="label" for="check_number">Check number <span class="font-normal text-slate-500">(checks only)</span></label><input class="input" id="check_number" name="check_number" inputmode="numeric" value="{{ old('check_number') }}"></div>
            <div class="sm:col-span-2"><label class="label" for="reference">Confirmation number <span class="font-normal text-slate-500">(optional)</span></label><input class="input" id="reference" name="reference" value="{{ old('reference') }}" placeholder="ACH trace, wire confirmation, Zelle or Venmo ID"></div>
            <div class="sm:col-span-2"><label class="label" for="note">Note <span class="font-normal text-slate-500">(optional)</span></label><textarea class="input" id="note" name="note" rows="2">{{ old('note') }}</textarea></div>
            <div class="sm:col-span-2"><label class="label" for="receipt">Receipt screenshot or PDF <span class="font-normal text-slate-500">(optional)</span></label><input class="input" id="receipt" name="receipt" type="file" accept="application/pdf,image/png,image/jpeg"><p class="hint">Please hide your own bank account and routing numbers.</p></div>
            <div class="sm:col-span-2 rounded-md bg-slate-50 p-3 text-sm text-slate-600">
                <strong class="block text-slate-900">What happens next</strong>
                <ol class="mt-1 list-decimal space-y-0.5 pl-5">
                    <li>{{ $business['name'] }} is notified and looks for your payment.</li>
                    <li>Once it arrives, they confirm it and the invoice is marked paid.</li>
                    <li>You get a thank-you email with the paid invoice for your records.</li>
                </ol>
            </div>
            <div class="sm:col-span-2"><button class="btn btn-primary btn-lg w-full sm:w-auto">Send payment report</button></div>
        </form>
    </details>
    @endif
</section>
@endif
@if ($claims->whereIn('status', ['confirmed'])->isNotEmpty())
    <p class="mt-4 text-sm text-slate-600">Confirmed payments are reflected in the balance above.</p>
@endif
@if (! empty($w9Available) && ! $preview)
<section id="w9" class="card card-body mt-8 flex scroll-mt-4 flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-base">Need {{ $business['name'] }}’s W-9?</h2>
        <p class="text-sm text-slate-600">Get their completed IRS Form W-9 as a PDF for your records. We’ll also email you a copy.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if (session('w9_ready'))
            <form method="post" action="/i/v/{{ $invoice->ulid }}/w9/download">@csrf<button class="btn btn-primary">Download W-9 (PDF)</button></form>
        @else
            <form method="post" action="/i/v/{{ $invoice->ulid }}/w9">@csrf<button class="btn">Request W-9</button></form>
        @endif
    </div>
</section>
@endif
@endsection
