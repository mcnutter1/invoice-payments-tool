@extends('layouts.public', ['accent' => $accent])
@section('title', $version->title.' — '.$business->name())
@section('content')
<header class="mb-4 flex items-center gap-3">
    @if ($logo)<img src="{{ $logo }}" alt="{{ $business->name() }} logo" class="h-10 max-w-[9rem] object-contain">@endif
    <div><div class="font-semibold text-slate-900">{{ $business->name() }}</div><div class="text-xs text-slate-500">Agreement for {{ $recipient->name }}</div></div>
</header>
@if ($version->status === 'completed')
    <div class="alert alert-ok mb-4">This agreement is fully signed. Download your copy below.</div>
@elseif ($recipient->status === 'signed')
    <div class="alert alert-ok mb-4">You signed on {{ $recipient->signed_at->format('F j, Y') }}. We'll email the completed agreement when all parties have signed.</div>
@elseif ($recipient->status === 'declined' || $version->status === 'declined')
    <div class="alert alert-warn mb-4">This agreement was declined.</div>
@elseif (in_array($version->status, ['voided', 'expired']))
    <div class="alert alert-warn mb-4">This agreement is no longer available for signing ({{ $version->status }}). Contact {{ $business->name() }} for an updated version.</div>
@elseif (! $signing)
    <div class="alert alert-info mb-4">It's not your turn to sign yet. You'll get an email when it is.</div>
@endif
<div class="flex flex-wrap gap-2">
    @if ($version->rendered_document_id)<a class="btn" href="/c/r/{{ $recipient->id }}/document">Download document (PDF)</a>@endif
    @if ($version->signed_document_id && $version->status === 'completed')<a class="btn btn-primary" href="/c/r/{{ $recipient->id }}/signed">Download signed agreement</a>@endif
    @if ($version->certificate_document_id)<a class="btn" href="/c/r/{{ $recipient->id }}/certificate">Signature certificate</a>@endif
    <a class="btn btn-ghost" href="/c/r/{{ $recipient->id }}/consent">E-sign consent (copy)</a>
</div>
<article class="card mt-4">
    <div class="card-head"><h1 class="text-lg">{{ $version->title }}</h1><span class="text-xs text-slate-500">Version {{ $version->version }}</span></div>
    @if ($html)
        <div class="card-body prose-doc max-h-[60vh] overflow-auto text-sm" tabindex="0" aria-label="Agreement text">{!! $html !!}</div>
    @else
        <p class="card-body text-sm">Open the PDF above to review the full document before signing.</p>
    @endif
    <p class="border-t border-slate-100 px-5 py-2 font-mono text-[10px] text-slate-500">Document SHA-256: {{ $version->sha256 }}</p>
</article>

@if ($signing)
<section class="mt-6 space-y-4">
    @if (! $recipient->verified_at || $recipient->verified_at->lt(now()->subMinutes(30)))
        <div class="card card-body">
            <h2>Step 1 · Verify your email</h2>
            <p class="mt-1 text-sm text-slate-600">We'll send a 6-digit code to the email this invitation was sent to. This confirms access to that inbox; it does not verify legal identity.</p>
            <form method="post" action="/c/r/{{ $recipient->id }}/code" class="mt-3">@csrf<button class="btn">Email me a code</button></form>
            <form method="post" action="/c/r/{{ $recipient->id }}/verify" class="mt-3 flex flex-wrap items-end gap-2">@csrf
                <div><label class="label" for="code">Verification code</label><input class="input w-40 font-mono" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></div>
                <button class="btn btn-primary">Verify</button>
            </form>
        </div>
    @else
        <div class="alert alert-ok">Email verified. Review the document, then sign below.</div>
        @include('public._sign-form', ['action' => '/c/r/'.$recipient->id.'/sign'])
    @endif
    <details class="card"><summary class="card-head cursor-pointer"><h2>Decline to sign</h2></summary>
        <form method="post" action="/c/r/{{ $recipient->id }}/decline" class="card-body space-y-2" data-confirm="Decline this agreement?">@csrf
            <label class="label" for="reason">Reason</label><textarea class="input" id="reason" name="reason" rows="2" required></textarea>
            <button class="btn btn-danger">Decline</button>
            <p class="hint">Prefer paper? Contact {{ $business->name() }}{{ $business->public_email ? ' at '.$business->public_email : '' }} to arrange a non-electronic alternative.</p>
        </form>
    </details>
</section>
@endif
@endsection
