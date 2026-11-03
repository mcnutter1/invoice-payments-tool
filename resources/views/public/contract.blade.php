@extends('layouts.public', ['accent' => $accent, 'wide' => true])
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
        <h2 class="text-emerald-900">This agreement is fully executed</h2>
        <p class="mt-1 text-sm text-emerald-900">All parties have signed. Signatures and signing evidence are shown below the agreement; download your copy and the signature certificate for your records.</p>
        <div class="mt-3 grid gap-2 sm:flex">
            @if ($version->signed_document_id)<a class="btn btn-primary" href="/c/r/{{ $recipient->id }}/signed">Download signed agreement</a>@endif
            @if ($version->certificate_document_id)<a class="btn" href="/c/r/{{ $recipient->id }}/certificate">Signature certificate</a>@endif
            <a class="btn" href="#signatures">Signatures &amp; evidence ↓</a>
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
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
<ol class="flex items-center gap-2 text-xs font-medium sm:text-sm" aria-label="Signing steps">
    @foreach ($steps as $i => [$label, $done])
        @php $current = ! $done && ($i === 0 || $steps[$i - 1][1]); @endphp
        <li class="flex items-center gap-2">
            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs', 'bg-emerald-600 text-white' => $done, 'bg-brand-700 text-white' => $current, 'bg-slate-200 text-slate-600' => ! $done && ! $current])>{{ $done ? '✓' : $i + 1 }}</span>
            <span @class(['text-slate-900' => $current, 'text-slate-500' => ! $current])>{{ $label }}</span>
            @if (! $loop->last)<span class="h-px w-6 bg-slate-300 sm:w-10" aria-hidden="true"></span>@endif
        </li>
    @endforeach
</ol>
<a class="btn btn-sm" href="#sign">Go to signature ↓</a>
</div>
@endif

@component('contracts._sheet', ['business' => $business, 'contract' => $contract, 'version' => $version, 'html' => $html, 'logo' => $logo, 'accent' => $accent])
    @slot('actions')@if ($version->rendered_document_id)<a class="btn btn-sm" href="/c/r/{{ $recipient->id }}/document">PDF</a>@endif @endslot
    @slot('foot')<span class="break-all font-mono">SHA-256 {{ $version->sha256 }}</span><a href="/c/r/{{ $recipient->id }}/consent">E-sign consent (copy)</a>@endslot
@endcomponent

@if ($evidence)
<section id="signatures" class="mx-auto mt-6 max-w-3xl scroll-mt-4 space-y-4">
    <div class="card"><div class="card-head"><h2>Signatures</h2><span class="chip chip-green">Fully executed {{ $version->completed_at?->timezone($business->timezone)->format('M j, Y') }}</span></div>
        <div class="card-body grid gap-4 sm:grid-cols-2">
        @foreach ($evidence['signers'] as $s)
            @php $p = $s['recipient']; @endphp
            <div class="rounded-md border border-slate-200 p-3">
                <div class="text-xs text-slate-500">{{ $p->role === 'countersigner' ? 'For '.$business->name() : 'Client signer' }}</div>
                @if ($s['image'])
                    <img src="{{ $s['image'] }}" alt="Signature of {{ $p->typed_name ?? $p->name }}" class="mt-1 max-h-24 max-w-full object-contain">
                @elseif ($p->signed_at)
                    <div class="mt-1 break-words border-b border-slate-300 py-1 font-serif text-3xl italic text-slate-800">{{ $p->typed_name ?? $p->name }}</div>
                @endif
                <div class="mt-2 text-sm font-medium">{{ $p->typed_name ?? $p->name }}{{ $p->typed_title ? ', '.$p->typed_title : '' }}</div>
                <div class="text-xs text-slate-500">{{ $p->email }}</div>
                @if ($p->signed_at)
                    <div class="mt-1 text-xs text-slate-600">Signed {{ $p->signed_at->timezone($business->timezone)->format('M j, Y g:i:sa T') }} · {{ $p->signature_kind === 'draw' ? 'drawn' : 'typed' }} · {{ $p->verificationLabel() }}</div>
                    @if ($p->ip)<div class="text-xs text-slate-500">IP {{ $p->ip }}</div>@endif
                    @if ($p->signature_hash)<div class="break-all font-mono text-[10px] text-slate-500">Signature hash {{ $p->signature_hash }}</div>@endif
                @endif
            </div>
        @endforeach
        </div>
    </div>
    <div class="card"><div class="card-head"><h2>Signing evidence</h2></div>
        <div class="card-body space-y-3 text-sm">
            <dl class="grid gap-1 text-xs sm:grid-cols-3">
                @if ($version->sha256)<dt class="text-slate-500">Original document SHA-256</dt><dd class="break-all font-mono sm:col-span-2">{{ $version->sha256 }}</dd>@endif
                @if ($version->signed_sha256)<dt class="text-slate-500">Signed document SHA-256</dt><dd class="break-all font-mono sm:col-span-2">{{ $version->signed_sha256 }}</dd>@endif
            </dl>
            @if ($evidence['events']->isNotEmpty())
            <div class="overflow-x-auto"><table class="tbl text-xs"><thead><tr><th>#</th><th>When (UTC)</th><th>Event</th><th>Recipient</th><th>IP</th></tr></thead><tbody>
                @foreach ($evidence['events'] as $e)
                    <tr><td>{{ $e->seq }}</td><td class="whitespace-nowrap">{{ $e->created_at->utc()->format('Y-m-d H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td>
                        <td>{{ optional($evidence['recipients']->firstWhere('id', $e->contract_recipient_id))->email }}</td><td>{{ $e->ip }}</td></tr>
                @endforeach
            </tbody></table></div>
            @endif
            <p class="hint">Email verification confirms access to the recipient’s inbox at signing time; it does not verify legal identity. SHA-256 values are integrity evidence, not a certificate-based digital signature. The signature certificate PDF contains this record plus the electronic-records consent.</p>
        </div>
    </div>
</section>
@endif

@if ($signing)
<section id="sign" class="mx-auto mt-6 max-w-3xl scroll-mt-4 space-y-4">
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
