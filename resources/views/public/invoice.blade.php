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
@endphp

@if ($preview)
<div class="alert alert-info mb-4 flex flex-wrap items-center justify-between gap-2">
    <span>Preview — this is what {{ $invoice->client?->display_name ?? 'the client' }} sees{{ $invoice->isDraft() ? '. Dates and the invoice number are filled in when you issue it.' : '.' }}</span>
    <span class="flex gap-2"><a class="btn btn-sm" href="{{ $pdfUrl }}" target="_blank" rel="noopener">PDF</a><a class="btn btn-sm" href="{{ $backUrl }}">Back to invoice</a></span>
</div>
@endif

@include('public._invoice-sheet')

@if ($payable)
@php $track = $preview ? '' : '/i/v/'.$invoice->ulid.'/event'; @endphp
<section class="mt-8 scroll-mt-4 space-y-4" id="howtopay" aria-labelledby="howtopay-h">
    <div>
        <h2 id="howtopay-h" class="text-xl">How to pay</h2>
        <p class="text-sm text-slate-500">Pay {{ $M($invoice->balance_cents) }} using one of the options below, then let {{ $business['name'] }} know.</p>
    </div>
    <div class="grid gap-4 {{ ! empty($instructions['zelle']) && ! empty($instructions['check']) ? 'md:grid-cols-2' : '' }}">
        @if (! empty($instructions['zelle']))
            @php $z = $instructions['zelle']; @endphp
            <div class="card card-body">
                <div class="flex items-center justify-between"><h3 class="text-base">Zelle</h3><span class="chip chip-violet">Fastest</span></div>
                <p class="mt-1 text-sm text-slate-600">Send from your bank's app or website.</p>
                @if ($zelleQr)
                    <figure class="mt-4 flex flex-col items-center text-center">
                        <img src="{{ $zelleQr }}" alt="Zelle QR code for {{ $z['recipient_name'] }}" class="h-44 w-44 rounded-lg border border-slate-200 bg-white object-contain p-2">
                        <figcaption class="mt-2 text-xs text-slate-500">Scan with your bank's Zelle app</figcaption>
                    </figure>
                @endif
                {{-- With a QR code the typed details are optional, so they start collapsed. --}}
                <details class="group mt-4" @if(! $zelleQr) open @endif>
                    <summary class="btn w-full cursor-pointer list-none justify-center @if(! $zelleQr) hidden @endif"><span class="group-open:hidden">Show Zelle details</span><span class="hidden group-open:inline">Hide Zelle details</span></summary>
                <dl class="mt-4 divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Send to</dt><dd class="text-right font-medium">{{ $z['recipient_name'] }}</dd></div>
                    @foreach (array_filter(['Email' => $z['email'] ?? null, 'Mobile' => $z['phone'] ?? null]) as $k => $v)
                        <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">{{ $k }}</dt><dd class="flex min-w-0 items-center gap-2"><span class="truncate font-mono">{{ $v }}</span><button type="button" class="btn btn-sm" data-copy="{{ $v }}" data-track="zelle_{{ strtolower($k) }}" data-track-url="{{ $track }}">Copy</button></dd></div>
                    @endforeach
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Amount</dt><dd class="flex items-center gap-2"><span class="font-mono">{{ $amountValue }}</span><button type="button" class="btn btn-sm" data-copy="{{ $amountValue }}" data-track="amount" data-track-url="{{ $track }}">Copy</button></dd></div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Memo</dt><dd class="flex items-center gap-2"><span class="font-mono">{{ $memo }}</span><button type="button" class="btn btn-sm" data-copy="{{ $memo }}" data-track="memo" data-track-url="{{ $track }}">Copy</button></dd></div>
                </dl>
                </details>
            </div>
        @endif
        @if (! empty($instructions['check']))
            @php $c = $instructions['check']; @endphp
            <div class="card card-body">
                <h3 class="text-base">Mail a check</h3>
                <dl class="mt-3 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Payable to</dt><dd class="font-medium">{{ $c['payee'] }}</dd></div>
                    <div><dt class="text-slate-500">Mail to</dt><dd class="whitespace-pre-line">{{ $c['address'] }}</dd></div>
                    <div><dt class="text-slate-500">Memo</dt><dd class="font-mono">{{ $memo }}</dd></div>
                </dl>
                @unless ($preview)<a class="btn btn-sm mt-4 self-start" href="/i/v/{{ $invoice->ulid }}/remittance">Print remittance slip</a>@endunless
            </div>
        @endif
    </div>

    @if ($canReport)
    <details class="group" @if($errors->any()) open @endif>
        <summary class="summary-btn">
            <svg aria-hidden="true" viewBox="0 0 20 20" class="h-5 w-5 fill-current"><path d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z"/></svg>
            <span>I've sent payment</span>
            <span class="text-sm font-normal opacity-80 group-open:hidden">Tell {{ $business['name'] }}</span>
        </summary>
        <form method="post" action="/i/v/{{ $invoice->ulid }}/report-payment" enctype="multipart/form-data" class="card card-body mt-3 grid gap-4 sm:grid-cols-2">
            @csrf
            <div><label class="label" for="method">Method</label><select class="input" id="method" name="method">@foreach ($instructions['methods'] ?? [] as $m)<option value="{{ $m }}">{{ $m === 'zelle' ? 'Zelle' : 'Mailed check' }}</option>@endforeach</select></div>
            <div><label class="label" for="amount">Amount sent ($)</label><input class="input" id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" required value="{{ old('amount', $amountValue) }}"></div>
            <div><label class="label" for="reported_date">Date sent or mailed</label><input class="input" id="reported_date" name="reported_date" type="date" required value="{{ old('reported_date', now()->toDateString()) }}"></div>
            <div><label class="label" for="check_number">Check number (if check)</label><input class="input" id="check_number" name="check_number" inputmode="numeric" value="{{ old('check_number') }}"></div>
            <div class="sm:col-span-2"><label class="label" for="reference">Confirmation / reference (optional)</label><input class="input" id="reference" name="reference" value="{{ old('reference') }}"></div>
            <div class="sm:col-span-2"><label class="label" for="note">Note (optional)</label><textarea class="input" id="note" name="note" rows="2">{{ old('note') }}</textarea></div>
            <div class="sm:col-span-2"><label class="label" for="receipt">Receipt screenshot or PDF (optional)</label><input class="input" id="receipt" name="receipt" type="file" accept="application/pdf,image/png,image/jpeg"><p class="hint">Do not include bank account or routing numbers.</p></div>
            <div class="sm:col-span-2"><button class="btn btn-primary btn-lg w-full sm:w-auto">Send payment report</button><p class="hint mt-2">This notifies {{ $business['name'] }}. The invoice is marked paid only after they confirm receipt.</p></div>
        </form>
    </details>
    @endif
</section>
@endif
@if ($claims->whereIn('status', ['confirmed'])->isNotEmpty())
    <p class="mt-4 text-sm text-slate-600">Confirmed payments are reflected in the balance above.</p>
@endif
@endsection
