@extends('layouts.public', ['accent' => $accent])
@section('title', $version->title.' — '.$business->name())
@section('content')
@php
    $verified = $recipient->verified_at && $recipient->verified_at->gt(now()->subMinutes(30));
    $readyToSign = $signing && (! $requireCode || $verified);
    $steps = array_values(array_filter([
        ['Review', true],
        $requireCode ? ['Verify email', $verified] : null,
        ['Sign', $recipient->status === 'signed'],
    ]));
@endphp
<p class="mb-4 text-sm text-slate-600">Agreement from <span class="font-semibold text-slate-900">{{ $business->name() }}</span> for {{ $recipient->name }}</p>

@if ($version->status === 'completed')
    <div class="card card-body mb-4 border-emerald-200 bg-emerald-50">
        <h2 class="text-emerald-900">This agreement is fully signed</h2>
        <p class="mt-1 text-sm text-emerald-900">Download your copy and the signature certificate for your records.</p>
        <div class="mt-3 grid gap-2 sm:flex">
            @if ($version->signed_document_id)<a class="btn btn-primary" href="/c/r/{{ $recipient->id }}/signed">Download signed agreement</a>@endif
            @if ($version->certificate_document_id)<a class="btn" href="/c/r/{{ $recipient->id }}/certificate">Signature certificate</a>@endif
        </div>
    </div>
@elseif ($recipient->status === 'signed')
    <div class="alert alert-ok mb-4">You signed on {{ $recipient->signed_at->format('F j, Y') }}. We'll email the completed agreement when all parties have signed.</div>
@elseif ($recipient->status === 'declined' || $version->status === 'declined')
    <div class="alert alert-warn mb-4">This agreement was declined.</div>
@elseif (in_array($version->status, ['voided', 'expired']))
    <div class="alert alert-warn mb-4">This agreement is no longer available for signing ({{ $version->status }}). Contact {{ $business->name() }} for an updated version.</div>
@elseif (! $signing)
    <div class="alert alert-info mb-4">It's not your turn to sign yet. You'll get an email when it is.</div>
@endif

@if ($signing)
<ol class="mb-4 flex items-center gap-2 text-xs font-medium sm:text-sm" aria-label="Signing steps">
    @foreach ($steps as $i => [$label, $done])
        @php $current = ! $done && ($i === 0 || $steps[$i - 1][1]); @endphp
        <li class="flex items-center gap-2">
            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs', 'bg-emerald-600 text-white' => $done, 'bg-brand-700 text-white' => $current, 'bg-slate-200 text-slate-600' => ! $done && ! $current])>{{ $done ? '✓' : $i + 1 }}</span>
            <span @class(['text-slate-900' => $current, 'text-slate-500' => ! $current])>{{ $label }}</span>
            @if (! $loop->last)<span class="h-px w-6 bg-slate-300 sm:w-10" aria-hidden="true"></span>@endif
        </li>
    @endforeach
</ol>
@endif

@component('contracts._sheet', ['business' => $business, 'contract' => $contract, 'version' => $version, 'html' => $html, 'logo' => $logo, 'accent' => $accent, 'scroll' => $signing])
    @slot('actions')@if ($version->rendered_document_id)<a class="btn btn-sm" href="/c/r/{{ $recipient->id }}/document">PDF</a>@endif @endslot
    @slot('foot')<span class="break-all font-mono">SHA-256 {{ $version->sha256 }}</span><a href="/c/r/{{ $recipient->id }}/consent">E-sign consent (copy)</a>@endslot
@endcomponent

@if ($signing)
<section class="mt-6 space-y-4">
    @if (! $readyToSign)
        <div class="card card-body">
            <h2>Verify your email</h2>
            <p class="mt-1 text-sm text-slate-600">We'll send a 6-digit code to the email this invitation was sent to. This confirms access to that inbox; it does not verify legal identity.</p>
            <form method="post" action="/c/r/{{ $recipient->id }}/code" class="mt-3">@csrf<button class="btn w-full sm:w-auto">Email me a code</button></form>
            <form method="post" action="/c/r/{{ $recipient->id }}/verify" class="mt-4 flex flex-wrap items-end gap-2">@csrf
                <div class="flex-1 sm:flex-none"><label class="label" for="code">Verification code</label><input class="input font-mono text-lg tracking-widest sm:w-44" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></div>
                <button class="btn btn-primary">Verify</button>
            </form>
        </div>
    @else
        @if ($requireCode)<div class="alert alert-ok">Email verified. Review the document, then sign below.</div>@endif
        @include('public._sign-form', ['action' => '/c/r/'.$recipient->id.'/sign'])
    @endif
    <details class="card"><summary class="card-head cursor-pointer list-none"><h2 class="text-base">Decline to sign</h2><span class="text-xs text-slate-500">Show</span></summary>
        <form method="post" action="/c/r/{{ $recipient->id }}/decline" class="card-body space-y-2" data-confirm="Decline this agreement?">@csrf
            <label class="label" for="reason">Reason</label><textarea class="input" id="reason" name="reason" rows="2" required></textarea>
            <button class="btn btn-danger">Decline</button>
            <p class="hint">Prefer paper? Contact {{ $business->name() }}{{ $business->public_email ? ' at '.$business->public_email : '' }} to arrange a non-electronic alternative.</p>
        </form>
    </details>
</section>
@endif
@endsection
