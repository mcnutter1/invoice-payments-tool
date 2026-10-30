<!DOCTYPE html>
<html><head><meta charset="utf-8">
@php
    $ac = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? $accent : '#1f5f8b';
    $font = $font ?? 'DejaVu Sans';
    $client = $contract->client;
    $start = $contract->term_start_date ?? $contract->effective_date;
    $hasTitle = (bool) preg_match('/^\s*<h1\b[^>]*>(.*?)<\/h1>/is', (string) $html, $titleMatch);
    if ($hasTitle) $html = substr($html, strlen($titleMatch[0]));
    $sigs = ($signatures ?? null) ?: $recipients->map(fn ($r) => ['recipient' => $r, 'image' => null]);
@endphp
<style>
    @page { margin: 96px 52px 72px 52px; }
    body { font-family: "Inter", "DejaVu Sans", sans-serif; font-size: 9.5px; color: #0f172a; line-height: 1.55; }
    header { position: fixed; top: -74px; left: 0; right: 0; height: 60px; border-bottom: 1px solid #e2e8f0; }
    header .bar { height: 3px; background: {{ $ac }}; margin-bottom: 10px; }
    footer { position: fixed; bottom: -50px; left: 0; right: 0; font-size: 7.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 5px; }
    footer .pg:after { content: "Page " counter(page); }
    table { border-collapse: collapse; }
    .logo { max-height: 38px; max-width: 160px; }
    .kicker { color: #64748b; font-size: 8px; }
    .muted { color: #64748b; font-size: 8.5px; }
    .title { font-size: 16px; font-weight: 600; text-align: center; margin: 4px 0 18px; }
    .parties { width: 100%; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; color: #475569; }
    .parties td { width: 50%; vertical-align: top; padding: 9px 12px 9px 0; }
    .label { color: #64748b; font-size: 7px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; margin-bottom: 2px; }
    .party { font-size: 10.5px; font-weight: 600; color: #0f172a; }
    .terms { width: 100%; border-bottom: 1px solid #e2e8f0; margin-bottom: 16px; }
    .terms td { padding: 7px 12px 7px 0; vertical-align: top; width: 50%; }
    .terms .k { font-size: 7px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; }
    .terms .v { color: #1e293b; }
    .body { font-size: 9.5px; line-height: 1.65; color: #1e293b; }
    .body strong, .body b { font-weight: 600; color: #0f172a; }
    .body h1 { font-size: 12px; font-weight: 600; margin: 14px 0 8px; }
    .body h2 { font-size: 10.5px; font-weight: 600; color: #0f172a; margin: 14px 0 5px; page-break-after: avoid; }
    .body h3 { font-size: 10px; font-weight: 600; margin: 12px 0 4px; page-break-after: avoid; } .body h4 { font-size: 9.5px; font-weight: 600; margin: 10px 0 4px; }
    .body p { margin: 0 0 8px; } .body ul, .body ol { margin: 0 0 8px 18px; padding: 0; } .body li { margin: 0 0 3px; } .body li ul, .body li ol { margin: 3px 0 0 16px; }
    .body blockquote { margin: 0 0 8px; padding-left: 10px; border-left: 2px solid #cbd5e1; color: #475569; }
    .body hr { border: 0; border-top: 1px solid #e2e8f0; margin: 12px 0; }
    .body a { color: #0f172a; }
    .body table { border-collapse: collapse; width: 100%; margin: 0 0 8px; }
    .body th, .body td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
    .sigs { margin-top: 28px; page-break-inside: avoid; }
    .sigs h2 { font-size: 10.5px; font-weight: 600; margin: 0 0 10px; }
    .sig { border-top: 1px solid #e2e8f0; padding: 8px 0; page-break-inside: avoid; }
    .sigline { height: 46px; border-bottom: 1px solid #0f172a; margin: 4px 0; font-family: "DejaVu Serif", serif; font-style: italic; font-size: 20px; }
    .sigimg { max-height: 44px; }
    .watermark { position: fixed; top: 280px; left: 60px; font-size: 110px; color: rgba(148,163,184,0.25); transform: rotate(-30deg); }
</style></head><body>
@isset($watermark)<div class="watermark">{{ $watermark }}</div>@endisset
<header>
    <div class="bar"></div>
    <table style="width:100%"><tr>
        <td style="vertical-align: top;">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<span style="font-size: 13px; font-weight: 600;">{{ $business->name() }}</span>@endif</td>
        <td style="text-align:right; vertical-align: top;" class="kicker">Agreement No. <strong style="color:#1e293b">{{ $contract->number }}</strong><br>Version {{ $version->version }}</td>
    </tr></table>
</header>
<footer><table style="width:100%"><tr><td>{{ $contract->number }} v{{ $version->version }} · {{ $business->name() }}@isset($original_sha256) · Original SHA-256 {{ $original_sha256 }}@endisset</td><td style="text-align:right"><span class="pg"></span></td></tr></table></footer>

<div class="title">@if ($hasTitle){!! $titleMatch[1] !!}@else{{ $version->title }}@endif</div>
<table class="parties"><tr>
    <td>
        <div class="label">Provider</div>
        <div class="party">{{ $business->legal_name ?: $business->name() }}</div>
        @foreach ($business->addressLines() as $line)<div>{{ $line }}</div>@endforeach
        @if ($business->public_email)<div>{{ $business->public_email }}</div>@endif
    </td>
    <td>
        <div class="label">Client</div>
        <div class="party">{{ $client?->legal_name ?: $client?->display_name }}</div>
        @foreach ($client?->billingAddressLines() ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if ($client?->email)<div>{{ $client->email }}</div>@endif
    </td>
</tr></table>
<table class="terms"><tr>
    <td><div class="k">Effective date</div><div class="v">{{ $contract->effective_date?->format('F j, Y') ?? '—' }}</div></td>
    <td><div class="k">Term</div><div class="v">{{ $start ? $start->format('F j, Y').' – '.($contract->term_end_date?->format('F j, Y') ?? 'until terminated') : '—' }}</div></td>
</tr></table>

<div class="body">{!! $html !!}</div>

<div class="sigs">
    <h2>Signatures</h2>
    <table style="width:100%"><tr>
    @foreach ($sigs->values() as $i => $s)
        @php $r = $s['recipient']; $done = ! empty($signed) && $r->signed_at; @endphp
        @if ($i > 0 && $i % 2 === 0)</tr><tr>@endif
        <td style="width:50%; vertical-align: top; padding: 0 {{ $i % 2 ? '0' : '6px' }} 10px {{ $i % 2 ? '6px' : '0' }};">
            <div class="sig">
                <div class="label">{{ $r->role === 'countersigner' ? 'For '.($business->legal_name ?: $business->name()) : 'For '.($client?->legal_name ?: $client?->display_name) }}</div>
                <div class="sigline">@if ($done)@if ($s['image'])<img class="sigimg" src="{{ $s['image'] }}" alt="">@else{{ $r->typed_name }}@endif @endif</div>
                <div style="font-weight: bold;">{{ $done && $r->typed_name ? $r->typed_name : $r->name }}</div>
                <div class="muted">{{ ($done && $r->typed_title) ? $r->typed_title : ($r->title ?: ' ') }}</div>
                <div class="muted">Date: {{ $done ? $r->signed_at->utc()->format('M j, Y H:i').' UTC' : '________________' }}</div>
                @if ($done)<div class="muted" style="font-size: 7.5px;">Signed electronically · {{ $r->signature_kind === 'draw' ? 'drawn' : 'typed' }} · {{ $r->verificationLabel() }}</div>@endif
            </div>
        </td>
    @endforeach
    @if ($sigs->count() % 2 === 1)<td></td>@endif
    </tr></table>
</div>
</body></html>
