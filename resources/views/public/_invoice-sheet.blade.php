{{-- Client-facing invoice sheet, styled after the PDF. Shares variables with public/invoice.blade.php. --}}
@php
    $showQty = $template['show_quantity'] ?? true;
    $agreementNo = $invoice->contractVersion?->contract?->number;
@endphp
<article class="inv-sheet" style="--accent: {{ $accent }}">
    <div class="inv-bar"></div>

    <header class="grid gap-6 px-5 pt-6 sm:grid-cols-2 sm:px-10 sm:pt-9">
        <div class="min-w-0">
            @if ($logo)<img src="{{ $logo }}" alt="{{ $business['name'] }}" class="mb-3 h-12 max-w-[11rem] object-contain">
            @else<div class="inv-brand">{{ $business['name'] }}</div>@endif
            <div class="mt-3 space-y-0.5 break-words text-sm text-slate-600">
                <div class="font-semibold text-slate-900">{{ $business['legal_name'] ?: $business['name'] }}</div>
                @foreach ($business['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
                @if (! empty($business['phone']))<div>{{ $business['phone'] }}</div>@endif
                @if (! empty($business['email']))<div>{{ $business['email'] }}</div>@endif
            </div>
        </div>
        <div class="sm:text-right">
            <div class="inv-title">Invoice</div>
            <div class="mt-1 flex items-center gap-2 sm:justify-end">
                <span class="font-mono text-base font-semibold text-slate-900">{{ $invoice->number ?? 'DRAFT' }}</span>
                <span class="chip {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>
            <dl class="inv-meta mt-4">
                <div><dt>Invoice date</dt><dd>{{ $invoice->issue_date?->format('M j, Y') ?? '—' }}</dd></div>
                <div><dt>Due date</dt><dd class="font-semibold">{{ $invoice->due_date?->format('M j, Y') ?? 'On receipt' }}</dd></div>
                @if ($invoice->service_start)<div><dt>Service period</dt><dd>{{ $invoice->service_start->format('M j, Y') }} – {{ $invoice->service_end?->format('M j, Y') }}</dd></div>@endif
                @if ($invoice->po_number)<div><dt>PO / reference</dt><dd>{{ $invoice->po_number }}</dd></div>@endif
                @if ($agreementNo)<div><dt>Agreement</dt><dd>{{ $agreementNo }} v{{ $invoice->contractVersion->version }}</dd></div>@endif
            </dl>
        </div>
    </header>

    @if ($agreementTitle)
        <div class="inv-callout mx-5 mt-6 sm:mx-10">
            Invoice related to agreement:
            @if ($agreement)<a class="font-semibold" href="/i/v/{{ $invoice->ulid }}/agreement">{{ $agreementTitle }}</a>
                @if ($agreement->certificate_document_id)<a class="ml-1 text-xs text-slate-500" href="/i/v/{{ $invoice->ulid }}/agreement/certificate">(signature certificate)</a>@endif
            @else<span class="font-semibold text-slate-900">{{ $agreementTitle }}</span>@if ($invoice->contractVersion?->status === 'partially_signed') <span class="text-xs text-slate-500">(awaiting signatures)</span>@endif @endif
        </div>
    @endif

    <section class="grid gap-6 px-5 pt-6 sm:grid-cols-2 sm:px-10">
        <div class="min-w-0 break-words text-sm text-slate-600">
            <div class="inv-label">Bill to</div>
            <div class="mt-1 font-semibold text-slate-900">{{ ($client['legal_name'] ?? null) ?: ($client['display_name'] ?? '') }}</div>
            @foreach ($client['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
            @if (! empty($client['email']))<div>{{ $client['email'] }}</div>@endif
        </div>
        <div class="inv-due">
            @if ($invoice->status === 'void')
                <div class="text-lg font-semibold text-slate-900">Cancelled</div>
                <p class="mt-1 text-sm text-slate-600">This invoice was cancelled by {{ $business['name'] }} and is no longer payable.</p>
            @elseif ($invoice->balance_cents <= 0)
                <div class="text-sm text-slate-500">Amount due</div>
                <div class="text-3xl font-semibold text-emerald-700">Paid in full</div>
                <p class="mt-1 text-sm text-slate-600">Thank you! No payment is due.</p>
            @else
                <div class="text-sm text-slate-500">{{ $invoice->paid_cents > 0 ? 'Remaining balance' : 'Amount due' }}</div>
                <div class="text-3xl font-semibold tabular-nums tracking-tight text-slate-900 sm:text-4xl">{{ $M($invoice->balance_cents) }}</div>
                <div class="mt-1 text-sm {{ $invoice->isOverdue() ? 'font-semibold text-red-700' : 'text-slate-600' }}">Due {{ $invoice->due_date?->format('F j, Y') ?? 'on receipt' }}{{ $invoice->isOverdue() ? ' · past due' : '' }}</div>
                @if ($invoice->paid_cents > 0)<p class="mt-1 text-sm text-slate-600">{{ $M($invoice->paid_cents) }} received of {{ $M($invoice->total_cents) }}.</p>@endif
            @endif
            <div class="mt-4 grid gap-2 sm:flex sm:flex-wrap">
                @if ($payable)<a class="btn btn-primary" href="{{ $preview ? '#howtopay' : '/i/v/'.$invoice->ulid.'/pay' }}">Pay now</a>@endif
                <a class="btn" href="{{ $preview ? $pdfUrl : '/i/v/'.$invoice->ulid.'/pdf' }}" @if($preview) target="_blank" rel="noopener" @endif>Download PDF</a>
                @if (! empty($instructions['check']) && $payable && ! $preview)<a class="btn" href="/i/v/{{ $invoice->ulid }}/remittance">Remittance slip</a>@endif
            </div>
        </div>
    </section>

    @if ($openClaim)
        <div class="alert alert-info mx-5 mt-5 sm:mx-10"><strong>Payment reported — awaiting confirmation.</strong> You reported a {{ $openClaim->method === 'zelle' ? 'Zelle' : 'check' }} payment of {{ $M($openClaim->reported_amount_cents) }} on {{ $openClaim->reported_date->format('M j') }}. {{ $business['name'] }} will confirm once it arrives.</div>
    @endif

    <section class="mt-8 px-5 sm:px-10">
        {{-- Mobile: stacked items --}}
        <div class="inv-thead rounded-t-md px-3 py-2 sm:hidden">Items</div>
        <ul class="divide-y divide-slate-100 border-b border-slate-200 sm:hidden">
            @foreach ($lines as $l)
                <li class="flex justify-between gap-3 px-1 py-3 text-sm">
                    <div class="min-w-0"><div class="font-semibold text-slate-900">{{ $l->name }}</div>
                        @if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif
                        @if($showQty)<div class="text-xs text-slate-500">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }} × ${{ number_format((float) $l->unit_price, 2) }}</div>@endif</div>
                    <div class="whitespace-nowrap font-medium tabular-nums">{{ $M($l->amount_cents) }}</div>
                </li>
            @endforeach
        </ul>
        {{-- Desktop: table like the PDF --}}
        <table class="inv-table hidden sm:table">
            <thead class="inv-thead"><tr><th>Description</th>@if($showQty)<th class="num w-20">Qty</th><th class="num w-32">Rate</th>@endif<th class="num w-36">Amount</th></tr></thead>
            <tbody>
            @foreach ($lines as $l)
                <tr><td><div class="font-semibold text-slate-900">{{ $l->name }}</div>@if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif</td>
                    @if($showQty)<td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}</td><td class="num">${{ number_format((float) $l->unit_price, 2) }}</td>@endif
                    <td class="num">{{ $M($l->amount_cents) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <dl class="inv-totals">
            <div><dt>Subtotal</dt><dd>{{ $M($invoice->subtotal_cents) }}</dd></div>
            @if ($invoice->discount_cents)<div><dt>Discount</dt><dd>−{{ $M($invoice->discount_cents) }}</dd></div>@endif
            @foreach ($tax_lines as $t)<div><dt>{{ $t->name }}</dt><dd>{{ $M($t->tax_cents) }}</dd></div>@endforeach
            <div class="inv-grand"><dt>Total</dt><dd>{{ $M($invoice->total_cents) }}</dd></div>
            @if ($invoice->paid_cents)<div><dt>Paid</dt><dd>−{{ $M($invoice->paid_cents) }}</dd></div>@endif
            @if ($invoice->credited_cents + $invoice->written_off_cents)<div><dt>Credits</dt><dd>−{{ $M($invoice->credited_cents + $invoice->written_off_cents) }}</dd></div>@endif
            @if ($invoice->paid_cents || $invoice->credited_cents + $invoice->written_off_cents)<div class="font-semibold text-slate-900"><dt>Balance due</dt><dd>{{ $M(max(0, $invoice->balance_cents)) }}</dd></div>@endif
        </dl>
    </section>

    @if ($invoice->customer_note || $invoice->terms)
        <section class="mx-5 mt-6 border-t border-slate-200 pb-8 pt-5 text-sm text-slate-600 sm:mx-10">
            @if ($invoice->customer_note)<div class="inv-label">Notes</div><p class="mt-1 whitespace-pre-line">{{ $invoice->customer_note }}</p>@endif
            @if ($invoice->terms)
                <div class="inv-label {{ $invoice->customer_note ? 'mt-5' : '' }}">Terms &amp; conditions</div>
                <div class="mt-2 space-y-2 text-xs leading-5 text-slate-500 [&_strong]:font-semibold [&_strong]:text-slate-700">{!! \App\Support\InvoiceTerms::toHtml(\App\Support\InvoiceTerms::resolve($invoice->terms, $invoice)) !!}</div>
            @endif
        </section>
    @else
        <div class="pb-8"></div>
    @endif
</article>
