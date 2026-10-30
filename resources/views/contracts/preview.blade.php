@extends('layouts.public', ['accent' => $accent])
@section('title', 'Preview · '.$version->title)
@section('content')
@php $bu = '/b/'.$business->ulid; $signers = $version->recipients->whereIn('role', ['client_signer', 'countersigner'])->sortBy('sign_order'); @endphp
<div class="alert alert-info mb-4 flex flex-wrap items-center justify-between gap-2">
    <span>Preview — this is how signers see version {{ $version->version }} ({{ str_replace('_', ' ', $version->status) }}).</span>
    <span class="flex gap-2">
        <a class="btn btn-sm" href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $version->version }}/document" target="_blank" rel="noopener">PDF</a>
        <a class="btn btn-sm" href="{{ $bu }}/contracts/{{ $contract->id }}">Back to agreement</a>
    </span>
</div>
<header class="mb-5 flex items-center gap-3">
    @if ($logo)<img src="{{ $logo }}" alt="{{ $business->name() }}" class="h-11 max-w-[9rem] object-contain">@endif
    <div class="min-w-0"><div class="truncate font-semibold text-slate-900">{{ $business->name() }}</div><div class="truncate text-xs text-slate-500">Agreement for {{ $contract->client->display_name }}</div></div>
</header>
<article class="card overflow-hidden">
    <div class="card-head">
        <div class="min-w-0"><h1 class="truncate text-lg">{{ $version->title }}</h1><div class="text-xs text-slate-500">{{ $contract->number }} · Version {{ $version->version }}</div></div>
    </div>
    @if ($html)
        <div class="prose-doc px-5 py-5 text-[15px] leading-relaxed sm:px-8 sm:text-sm" aria-label="Agreement text">{!! $html !!}</div>
    @else
        <p class="card-body text-sm">This version is an uploaded document. Open the PDF to view it.</p>
    @endif
    @if ($version->sha256)
        <div class="border-t border-slate-100 px-5 py-2 text-[11px] text-slate-500"><span class="break-all font-mono">SHA-256 {{ $version->sha256 }}</span></div>
    @endif
</article>
@if ($signers->isNotEmpty())
<section class="card mt-4">
    <div class="card-head"><h2>Signers</h2></div>
    <ul class="card-body divide-y divide-slate-100 text-sm">
        @foreach ($signers as $r)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <span><span class="font-medium text-slate-900">{{ $r->name }}</span>@if ($r->title) <span class="text-slate-500">· {{ $r->title }}</span>@endif
                    <span class="block text-xs text-slate-500">{{ $r->role === 'countersigner' ? $business->name() : $contract->client->display_name }} · {{ $r->email }}</span></span>
                @include('partials.status', ['s' => $r->status])
            </li>
        @endforeach
    </ul>
</section>
@endif
@endsection
