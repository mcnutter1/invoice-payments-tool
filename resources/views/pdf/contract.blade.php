<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 70px 56px 70px 56px; }
    body { font-family: "DejaVu Serif", serif; font-size: 10.5px; color: #111827; line-height: 1.5; }
    footer { position: fixed; bottom: -48px; left: 0; right: 0; font-family: "DejaVu Sans", sans-serif; font-size: 7.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 5px; }
    footer .pg:after { content: "Page " counter(page); }
    h1 { font-size: 18px; margin: 0 0 10px; } h2 { font-size: 13px; margin: 16px 0 6px; } h3 { font-size: 11px; margin: 12px 0 4px; }
    p { margin: 0 0 8px; } ul, ol { margin: 0 0 8px 18px; padding: 0; } li { margin: 0 0 3px; } li ul, li ol { margin: 3px 0 0 16px; }
    h4 { font-size: 10.5px; margin: 10px 0 4px; }
    blockquote { margin: 0 0 8px; padding-left: 10px; border-left: 3px solid #d1d5db; color: #374151; }
    hr { border: 0; border-top: 1px solid #d1d5db; margin: 12px 0; }
    a { color: #111827; }
    table.doc, .body table { border-collapse: collapse; width: 100%; margin: 0 0 8px; }
    .body th, .body td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
    .head { border-bottom: 3px solid {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? $accent : '#1f5f8b' }}; padding-bottom: 8px; margin-bottom: 14px; font-family: "DejaVu Sans", sans-serif; }
    .logo { max-height: 44px; max-width: 160px; }
    .sigs { margin-top: 24px; page-break-inside: avoid; font-family: "DejaVu Sans", sans-serif; }
    .sig { border: 1px solid #d1d5db; padding: 8px 10px; margin-bottom: 10px; page-break-inside: avoid; }
    .sigline { height: 46px; border-bottom: 1px solid #111827; margin-bottom: 4px; font-family: "DejaVu Serif", serif; font-style: italic; font-size: 20px; }
    .sigimg { max-height: 44px; }
    .muted { color: #6b7280; font-size: 8.5px; }
    .watermark { position: fixed; top: 280px; left: 60px; font-size: 110px; color: rgba(148,163,184,0.25); transform: rotate(-30deg); }
</style></head><body>
@isset($watermark)<div class="watermark">{{ $watermark }}</div>@endisset
<footer><table style="width:100%"><tr><td>{{ $contract->number }} v{{ $version->version }} · {{ $business->name() }}@isset($original_sha256) · Original SHA-256 {{ $original_sha256 }}@endisset</td><td style="text-align:right"><span class="pg"></span></td></tr></table></footer>
<div class="head"><table style="width:100%"><tr>
    <td>@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<strong>{{ $business->name() }}</strong>@endif</td>
    <td style="text-align:right; font-size: 9px;">{{ $contract->number }} · Version {{ $version->version }}</td>
</tr></table></div>
<div class="body">{!! $html !!}</div>
<div class="sigs">
    <h2>Signatures</h2>
    @foreach (($signatures ?? null) ?: $recipients->map(fn ($r) => ['recipient' => $r, 'image' => null]) as $s)
        @php $r = $s['recipient']; @endphp
        <div class="sig">
            <div class="muted">{{ $r->role === 'countersigner' ? 'For '.$business->legal_name : 'Client signer' }}</div>
            <div class="sigline">@if (! empty($signed) && $r->signed_at)@if ($s['image'])<img class="sigimg" src="{{ $s['image'] }}" alt="">@else{{ $r->typed_name }}@endif @endif</div>
            <table style="width:100%"><tr>
                <td>Name: {{ ! empty($signed) && $r->typed_name ? $r->typed_name : $r->name }}{{ (! empty($signed) && $r->typed_title) ? ', '.$r->typed_title : ($r->title ? ', '.$r->title : '') }}</td>
                <td style="text-align:right">Date: {{ ! empty($signed) && $r->signed_at ? $r->signed_at->utc()->format('Y-m-d H:i').' UTC' : '________________' }}</td>
            </tr></table>
            @if (! empty($signed) && $r->signed_at)<div class="muted">Signed electronically · {{ $r->signature_kind === 'draw' ? 'drawn' : 'typed' }} signature · {{ $r->verificationLabel() }} · see signature certificate</div>@endif
        </div>
    @endforeach
</div>
</body></html>
