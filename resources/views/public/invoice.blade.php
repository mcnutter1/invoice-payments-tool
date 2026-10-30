@extends('layouts.public', ['accent' => $accent])
@section('title', 'Invoice '.$invoice->number.' from '.($business['name'] ?? ''))
@section('content')
@php
    $M = fn ($c) => \App\Support\Money::format((int) $c);
    $memo = 'Invoice '.$invoice->number;
    $payable = $invoice->status === 'issued' && $invoice->balance_cents > 0;
    [$statusLabel, $statusClass] = match (true) {
        $invoice->status === 'void' => ['Cancelled', 'chip-gray'],
        $invoice->balance_cents <= 0 => ['Paid', 'chip-green'],
        (bool) $openClaim => ['Payment reported', 'chip-blue'],
        $invoice->isOverdue() => ['Past due', 'chip-red'],
        default => ['Due', 'chip-amber'],
    };
    $amountValue = number_format($invoice->balance_cents / 100, 2, '.', '');
@endphp

<article class="card overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            @if ($logo)<img src="{{ $logo }}" alt="{{ $business['name'] }}" class="h-11 max-w-[9rem] object-contain">@endif
            <div class="min-w-0">
                <div class="truncate text-base font-semibold text-slate-900">{{ $business['name'] }}</div>
                <div class="truncate text-xs text-slate-500">{{ implode(' · ', array_filter([$business['email'] ?? null, $business['phone'] ?? null])) }}</div>
            </div>
        </div>
        <div class="text-right">
            <div class="text-xs uppercase tracking-wide text-slate-500">Invoice</div>
            <div class="font-mono text-base font-semibold text-slate-900">{{ $invoice->number }}</div>
        </div>
    </header>

    <section class="border-y border-slate-100 bg-slate-50/70 px-5 py-6 sm:px-6">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="chip {{ $statusClass }}">{{ $statusLabel }}</span>
                @if ($invoice->status === 'void')
                    <p class="mt-3 text-sm text-slate-600">This invoice was cancelled by {{ $business['name'] }} and is no longer payable.</p>
                @elseif ($invoice->balance_cents <= 0)
                    <div class="mt-2 text-3xl font-semibold text-emerald-700 sm:text-4xl">Paid in full</div>
                    <p class="mt-1 text-sm text-slate-600">Thank you! No payment is due.</p>
                @else
                    <div class="mt-3 text-sm text-slate-500">{{ $invoice->paid_cents > 0 ? 'Remaining balance' : 'Amount due' }}</div>
                    <div class="text-4xl font-semibold tabular-nums tracking-tight text-slate-900 sm:text-5xl">{{ $M($invoice->balance_cents) }}</div>
                    <div class="mt-1 text-sm {{ $invoice->isOverdue() ? 'font-semibold text-red-700' : 'text-slate-600' }}">Due {{ $invoice->due_date?->format('F j, Y') }}{{ $invoice->isOverdue() ? ' · past due' : '' }}</div>
                    @if ($invoice->paid_cents > 0)<p class="mt-1 text-sm text-slate-600">{{ $M($invoice->paid_cents) }} received of {{ $M($invoice->total_cents) }}.</p>@endif
                @endif
            </div>
            <div class="flex flex-col gap-2 sm:items-end">
                @if ($payable)<a class="btn btn-primary btn-lg w-full sm:w-auto" href="/i/v/{{ $invoice->ulid }}/pay">Pay now</a>@endif
                <div class="grid grid-cols-2 gap-2 sm:flex">
                    <a class="btn" href="/i/v/{{ $invoice->ulid }}/pdf">Download PDF</a>
                    @if (! empty($instructions['check']) && $payable)<a class="btn" href="/i/v/{{ $invoice->ulid }}/remittance">Remittance slip</a>@endif
                </div>
            </div>
        </div>
        @if ($agreementTitle)
            <p class="mt-5 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                Invoice related to agreement:
                @if ($agreement)<a class="font-medium" href="/i/v/{{ $invoice->ulid }}/agreement">{{ $agreementTitle }}</a>
                    @if ($agreement->certificate_document_id)<a class="ml-1 text-xs text-slate-500" href="/i/v/{{ $invoice->ulid }}/agreement/certificate">(signature certificate)</a>@endif
                @else<span class="font-medium">{{ $agreementTitle }}</span>@if ($invoice->contractVersion?->status === 'partially_signed') <span class="text-xs text-slate-500">(awaiting signatures)</span>@endif @endif
            </p>
        @endif
        @if ($openClaim)
            <div class="alert alert-info mt-4"><strong>Payment reported — awaiting confirmation.</strong> You reported a {{ $openClaim->method === 'zelle' ? 'Zelle' : 'check' }} payment of {{ $M($openClaim->reported_amount_cents) }} on {{ $openClaim->reported_date->format('M j') }}. {{ $business['name'] }} will confirm once it arrives.</div>
        @endif
    </section>

    <section class="grid grid-cols-2 gap-4 px-5 py-5 text-sm sm:grid-cols-3 sm:px-6">
        <div class="col-span-2 sm:col-span-1">
            <div class="text-xs uppercase tracking-wide text-slate-500">Billed to</div>
            <div class="mt-1 font-medium text-slate-900">{{ $client['display_name'] ?? '' }}</div>
            @foreach ($client['address'] ?? [] as $line)<div class="text-slate-600">{{ $line }}</div>@endforeach
        </div>
        <div>
            <div class="text-xs uppercase tracking-wide text-slate-500">Issued</div>
            <div class="mt-1 text-slate-900">{{ $invoice->issue_date?->format('M j, Y') }}</div>
            @if ($invoice->po_number)<div class="mt-2 text-xs uppercase tracking-wide text-slate-500">PO / reference</div><div class="text-slate-900">{{ $invoice->po_number }}</div>@endif
        </div>
        <div>
            <div class="text-xs uppercase tracking-wide text-slate-500">Due</div>
            <div class="mt-1 text-slate-900">{{ $invoice->due_date?->format('M j, Y') ?? 'On receipt' }}</div>
        </div>
    </section>

    <section class="border-t border-slate-100">
        <ul class="divide-y divide-slate-100 sm:hidden">
            @foreach ($lines as $l)
                <li class="flex justify-between gap-3 px-5 py-3 text-sm">
                    <div class="min-w-0"><div class="font-medium text-slate-900">{{ $l->name }}</div>
                        @if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif
                        @if($template['show_quantity'] ?? true)<div class="text-xs text-slate-500">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }} × ${{ number_format((float) $l->unit_price, 2) }}</div>@endif</div>
                    <div class="whitespace-nowrap font-medium tabular-nums">{{ $M($l->amount_cents) }}</div>
                </li>
            @endforeach
        </ul>
        <table class="tbl hidden sm:table">
            <thead><tr><th class="pl-6">Description</th>@if($template['show_quantity'] ?? true)<th class="num">Qty</th><th class="num">Rate</th>@endif<th class="num pr-6">Amount</th></tr></thead>
            <tbody>
            @foreach ($lines as $l)
                <tr><td class="pl-6"><div class="font-medium text-slate-900">{{ $l->name }}</div>@if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif</td>
                    @if($template['show_quantity'] ?? true)<td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}</td><td class="num">${{ number_format((float) $l->unit_price, 2) }}</td>@endif
                    <td class="num pr-6">{{ $M($l->amount_cents) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <dl class="ml-auto max-w-sm space-y-1.5 px-5 py-5 text-sm sm:px-6">
            <div class="flex justify-between"><dt class="text-slate-600">Subtotal</dt><dd class="num">{{ $M($invoice->subtotal_cents) }}</dd></div>
            @if ($invoice->discount_cents)<div class="flex justify-between"><dt class="text-slate-600">Discount</dt><dd class="num">−{{ $M($invoice->discount_cents) }}</dd></div>@endif
            @foreach ($tax_lines as $t)<div class="flex justify-between"><dt class="text-slate-600">{{ $t->name }}</dt><dd class="num">{{ $M($t->tax_cents) }}</dd></div>@endforeach
            <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold"><dt>Total</dt><dd class="num">{{ $M($invoice->total_cents) }}</dd></div>
            @if ($invoice->paid_cents)<div class="flex justify-between"><dt class="text-slate-600">Paid</dt><dd class="num">−{{ $M($invoice->paid_cents) }}</dd></div>@endif
            @if ($invoice->credited_cents + $invoice->written_off_cents)<div class="flex justify-between"><dt class="text-slate-600">Credits</dt><dd class="num">−{{ $M($invoice->credited_cents + $invoice->written_off_cents) }}</dd></div>@endif
            <div class="flex justify-between rounded-md bg-slate-50 px-2 py-1.5 text-base font-semibold"><dt>Balance due</dt><dd class="num">{{ $M(max(0, $invoice->balance_cents)) }}</dd></div>
        </dl>
    </section>

    @if ($invoice->customer_note || $invoice->terms)
        <section class="border-t border-slate-100 px-5 py-4 text-sm text-slate-600 sm:px-6">
            @if ($invoice->customer_note)<p class="whitespace-pre-line">{{ $invoice->customer_note }}</p>@endif
            @if ($invoice->terms)<h3 class="mt-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Terms &amp; conditions</h3><p class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-500">{{ $invoice->terms }}</p>@endif
        </section>
    @endif
</article>

@if ($payable)
@php $track = '/i/v/'.$invoice->ulid.'/event'; @endphp
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
                <p class="mt-1 text-sm text-slate-600">Send from your bank's app or website. {{ $business['name'] }} never sees your bank details.</p>
                @if ($zelleQr)
                    <figure class="mt-4 flex flex-col items-center text-center">
                        <img src="{{ $zelleQr }}" alt="Zelle QR code for {{ $z['recipient_name'] }}" class="h-44 w-44 rounded-lg border border-slate-200 bg-white object-contain p-2">
                        <figcaption class="mt-2 text-xs text-slate-500">Scan with your bank's Zelle app</figcaption>
                    </figure>
                @endif
                <dl class="mt-4 divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Send to</dt><dd class="text-right font-medium">{{ $z['recipient_name'] }}</dd></div>
                    @foreach (array_filter(['Email' => $z['email'] ?? null, 'Mobile' => $z['phone'] ?? null]) as $k => $v)
                        <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">{{ $k }}</dt><dd class="flex min-w-0 items-center gap-2"><span class="truncate font-mono">{{ $v }}</span><button type="button" class="btn btn-sm" data-copy="{{ $v }}" data-track="zelle_{{ strtolower($k) }}" data-track-url="{{ $track }}">Copy</button></dd></div>
                    @endforeach
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Amount</dt><dd class="flex items-center gap-2"><span class="font-mono">{{ $amountValue }}</span><button type="button" class="btn btn-sm" data-copy="{{ $amountValue }}" data-track="amount" data-track-url="{{ $track }}">Copy</button></dd></div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2"><dt class="text-slate-500">Memo</dt><dd class="flex items-center gap-2"><span class="font-mono">{{ $memo }}</span><button type="button" class="btn btn-sm" data-copy="{{ $memo }}" data-track="memo" data-track-url="{{ $track }}">Copy</button></dd></div>
                </dl>
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
                <a class="btn btn-sm mt-4 self-start" href="/i/v/{{ $invoice->ulid }}/remittance">Print remittance slip</a>
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
