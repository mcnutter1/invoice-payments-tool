@extends('layouts.public', ['accent' => $accent, 'wide' => true])
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
@component('contracts._sheet', ['business' => $business, 'contract' => $contract, 'version' => $version, 'html' => $html, 'logo' => $logo, 'accent' => $accent])
    @if ($version->sha256)@slot('foot')<span class="break-all font-mono">SHA-256 {{ $version->sha256 }}</span>@endslot @endif
@endcomponent
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
