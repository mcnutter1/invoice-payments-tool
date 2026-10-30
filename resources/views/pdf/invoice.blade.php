<!DOCTYPE html>
<html><head><meta charset="utf-8">
@php
    $M = fn ($c) => \App\Support\Money::format((int) $c);
    $modern = ($template['template'] ?? 'classic') === 'modern';
    $showQty = $template['show_quantity'] ?? true;
    $showSku = $template['show_sku'] ?? false;
    $cols = 2 + ($showQty ? 2 : 0) + ($showSku ? 1 : 0);
@endphp
<style>
    @page { margin: 110px 44px 70px 44px; }
    body { font-family: "{{ $font }}", sans-serif; font-size: 10px; color: #1e293b; line-height: 1.4; }
    header { position: fixed; top: -86px; left: 0; right: 0; height: 76px; }
    footer { position: fixed; bottom: -50px; left: 0; right: 0; height: 36px; font-size: 8px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    footer .pg:after { content: "Page " counter(page); }
    .accent { color: {{ $accent }}; } .accent-bg { background: {{ $accent }}; color: #fff; }
    .brandbar { height: 6px; background: {{ $accent }}; margin-bottom: 10px; }
    h1 { font-size: {{ $modern ? '26px' : '20px' }}; margin: 0; letter-spacing: {{ $modern ? '1px' : '0' }}; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1px 0; } .muted { color: #64748b; } .right { text-align: right; } .num { text-align: right; white-space: nowrap; }
    .lines thead { display: table-header-group; }
    .lines th { {{ $modern ? 'background: '.$accent.'; color: #fff;' : 'border-bottom: 2px solid '.$accent.';' }} text-align: left; padding: 6px; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; }
    .lines th.num { text-align: right; }
    .lines td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .lines tr { page-break-inside: avoid; }
    .desc { color: #475569; font-size: 9px; white-space: pre-line; }
    .totals { width: 46%; margin-left: 54%; margin-top: 8px; page-break-inside: avoid; }
    .totals td { padding: 3px 6px; } .totals .grand td { font-weight: bold; font-size: 12px; border-top: 2px solid {{ $accent }}; }
    .due-box { border: 2px solid {{ $accent }}; padding: 8px 12px; margin: 10px 0 14px; }
    .due-box .amt { font-size: 18px; font-weight: bold; }
    .section { margin-top: 16px; page-break-inside: avoid; }
    .section h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .6px; margin: 0 0 4px; color: {{ $accent }}; }
    .box { border: 1px solid #e2e8f0; padding: 8px 10px; }
    .terms { page-break-inside: auto; } .terms p { margin: 0 0 4px; }
    .watermark { position: fixed; top: 280px; left: 60px; font-size: 110px; color: rgba(148,163,184,0.25); transform: rotate(-30deg); }
    .logo { max-height: 56px; max-width: 180px; }
</style></head>
<body>
@isset($watermark)<div class="watermark">{{ $watermark }}</div>@endisset
<header>
    @if ($modern)<div class="brandbar"></div>@endif
    <table><tr>
        <td style="width:55%; vertical-align: top;">
            @if ($logo)<img src="{{ $logo }}" class="logo" alt="">@else<div style="font-size:16px; font-weight:bold;" class="accent">{{ $business['name'] ?? '' }}</div>@endif
        </td>
        <td class="right" style="vertical-align: top;">
            <h1 class="{{ $modern ? 'accent' : '' }}">INVOICE</h1>
            <div style="font-size:12px; font-weight:bold;">{{ $invoice->number ?? 'DRAFT' }}</div>
            @if (! empty($template['header_text']))<div class="muted">{{ $template['header_text'] }}</div>@endif
        </td>
    </tr></table>
</header>
<footer>
    <table><tr><td>{{ $template['footer_text'] ?? '' }}</td><td class="right"><span class="pg"></span></td></tr></table>
</footer>

<table class="meta"><tr>
    <td style="width:50%; vertical-align: top;">
        <div style="font-weight:bold;">{{ $business['legal_name'] ?? $business['name'] ?? '' }}</div>
        @foreach ($business['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if (! empty($business['phone']))<div>{{ $business['phone'] }}</div>@endif
        @if (! empty($business['email']))<div>{{ $business['email'] }}</div>@endif
        @if (! empty($business['website']))<div>{{ $business['website'] }}</div>@endif
        @if (! empty($business['tax_id']))<div class="muted">Tax ID: {{ $business['tax_id'] }}</div>@endif
    </td>
    <td style="vertical-align: top;">
        <table>
            <tr><td class="muted">Invoice date</td><td class="right">{{ $invoice->issue_date?->format('M j, Y') ?? '—' }}</td></tr>
            <tr><td class="muted">Due date</td><td class="right" style="font-weight:bold;">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td></tr>
            @if ($invoice->po_number)<tr><td class="muted">PO / reference</td><td class="right">{{ $invoice->po_number }}</td></tr>@endif
            @if ($invoice->service_start)<tr><td class="muted">Service period</td><td class="right">{{ $invoice->service_start->format('M j, Y') }} – {{ $invoice->service_end?->format('M j, Y') }}</td></tr>@endif
            @if ($invoice->contractVersion)<tr><td class="muted">Agreement</td><td class="right">{{ $invoice->contractVersion->contract?->number }} v{{ $invoice->contractVersion->version }}</td></tr>@endif
        </table>
    </td>
</tr></table>
@if ($invoice->contractVersion)
    <div style="margin-top: 10px; padding: 5px 8px; border-left: 3px solid {{ $accent }}; background: #f8fafc;">Invoice related to agreement: <strong>{{ $invoice->contractVersion->title ?? $invoice->contractVersion->contract?->title }}</strong></div>
@endif

<table style="margin-top: 14px;"><tr>
    <td style="width:50%; vertical-align: top;">
        <div class="muted" style="text-transform: uppercase; font-size: 8px; letter-spacing: .6px;">Bill to</div>
        <div style="font-weight:bold;">{{ $client['legal_name'] ?? null ?: ($client['display_name'] ?? '') }}</div>
        @foreach ($client['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if (! empty($client['email']))<div>{{ $client['email'] }}</div>@endif
    </td>
    <td style="vertical-align: top;">
        @if ($template['due_date_prominent'] ?? true)
            <div class="due-box"><div class="muted">Amount due</div><div class="amt">{{ $M($invoice->isDraft() ? $invoice->total_cents : $invoice->balance_cents) }}</div><div>Due {{ $invoice->due_date?->format('F j, Y') ?? 'on receipt' }}</div></div>
        @endif
    </td>
</tr></table>

<table class="lines" style="margin-top: 8px;">
    <thead><tr>
        @if ($showSku)<th style="width: 12%;">SKU</th>@endif
        <th>Description</th>
        @if ($showQty)<th class="num" style="width: 10%;">Qty</th><th class="num" style="width: 14%;">Rate</th>@endif
        <th class="num" style="width: 16%;">Amount</th>
    </tr></thead>
    <tbody>
    @foreach ($lines as $l)
        <tr>
            @if ($showSku)<td>{{ $l->sku }}</td>@endif
            <td><div style="font-weight:bold;">{{ $l->name }}</div>@if ($l->description)<div class="desc">{{ $l->description }}</div>@endif
                @if ((float) $l->tax_rate > 0)<div class="muted" style="font-size:8px;">Taxable · {{ rtrim(rtrim((string) $l->tax_rate, '0'), '.') }}%</div>@endif</td>
            @if ($showQty)<td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}{{ $l->unit ? ' '.$l->unit : '' }}</td><td class="num">${{ number_format((float) $l->unit_price, 2) }}</td>@endif
            <td class="num">{{ $M($l->amount_cents) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="num">{{ $M($invoice->subtotal_cents) }}</td></tr>
    @if ($invoice->discount_cents)<tr><td>Discount</td><td class="num">−{{ $M($invoice->discount_cents) }}</td></tr>@endif
    @foreach ($tax_lines as $t)<tr><td>{{ $t->name }}</td><td class="num">{{ $M($t->tax_cents) }}</td></tr>@endforeach
    <tr class="grand"><td>Total</td><td class="num">{{ $M($invoice->total_cents) }}</td></tr>
</table>

@if (! empty($instructions['zelle']) || ! empty($instructions['check']))
<div class="section">
    <h3>How to pay</h3>
    <table><tr>
        @if (! empty($instructions['zelle']))
            <td style="vertical-align: top; width: 50%; padding-right: 8px;"><div class="box">
                <div style="font-weight:bold;">Zelle</div>
                <div>Send to: {{ $instructions['zelle']['recipient_name'] }}</div>
                @if (! empty($instructions['zelle']['email']))<div>{{ $instructions['zelle']['email'] }}</div>@endif
                @if (! empty($instructions['zelle']['phone']))<div>{{ $instructions['zelle']['phone'] }}</div>@endif
                <div>Memo: {{ $memo ?? 'invoice number' }}</div>
                @if (! empty($zelleQr))<div style="text-align: center; margin: 6px 0 2px;"><img src="{{ $zelleQr }}" alt="" style="width: 84px; height: 84px;"></div>@endif
                <div class="muted" style="font-size:8px;">Use your bank's app. We never ask for your bank login.</div>
            </div></td>
        @endif
        @if (! empty($instructions['check']))
            <td style="vertical-align: top;"><div class="box">
                <div style="font-weight:bold;">Check</div>
                <div>Payable to: {{ $instructions['check']['payee'] }}</div>
                <div style="white-space: pre-line;">{{ $instructions['check']['address'] }}</div>
                <div>Memo: {{ $memo ?? 'invoice number' }}</div>
            </div></td>
        @endif
    </tr></table>
</div>
@endif

@if ($invoice->customer_note)<div class="section"><h3>Notes</h3><div style="white-space: pre-line;">{{ $invoice->customer_note }}</div></div>@endif
@if ($invoice->terms)<div class="section terms" style="border-top: 1px solid #e2e8f0; padding-top: 8px;"><h3>Terms &amp; conditions</h3><div class="muted" style="font-size: 8px; line-height: 1.45;">{!! \App\Support\InvoiceTerms::toHtml(\App\Support\InvoiceTerms::resolve($invoice->terms, $invoice), 'color:#334155') !!}</div></div>@endif
</body></html>
