<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #111827; }
    h1 { font-size: 16px; } .sig { border: 1px solid #d1d5db; padding: 8px 10px; margin-bottom: 10px; }
    .sigline { height: 46px; border-bottom: 1px solid #111827; font-family: "DejaVu Serif", serif; font-style: italic; font-size: 20px; }
    .sigimg { max-height: 44px; } .muted { color: #6b7280; font-size: 8.5px; }
</style></head><body>
<h1>Signature page — {{ $contract->number }} v{{ $version->version }}: {{ $version->title }}</h1>
<p>This signature page applies to the uploaded document with SHA-256 <strong>{{ $original_sha256 }}</strong>. Keep it together with that document.</p>
@foreach ($signatures as $s)
    @php $r = $s['recipient']; @endphp
    <div class="sig">
        <div class="muted">{{ $r->role === 'countersigner' ? 'For '.$business->legal_name : 'Client signer' }}</div>
        <div class="sigline">@if ($s['image'])<img class="sigimg" src="{{ $s['image'] }}" alt="">@else{{ $r->typed_name }}@endif</div>
        <div>Name: {{ $r->typed_name }}{{ $r->typed_title ? ', '.$r->typed_title : '' }} · {{ $r->signed_at?->utc()->format('Y-m-d H:i') }} UTC</div>
    </div>
@endforeach
</body></html>
