@extends('layouts.app')
@section('title', 'Countersign')
@section('content')
<h1>Countersign {{ $contract->number }}</h1>
<p class="text-sm text-slate-500">You're signing on behalf of {{ $biz->name() }}. Your McNutt Cloud sign-in is used as identity verification.</p>
<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="card xl:col-span-2">
        <div class="card-head"><h2>{{ $version->title }}</h2><a href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $version->version }}/document" target="_blank" rel="noopener" class="text-sm">Open PDF</a></div>
        @if ($html)<div class="card-body prose-doc max-h-[36rem] overflow-auto text-sm">{!! $html !!}</div>@else<p class="card-body text-sm">Review the attached PDF before signing.</p>@endif
    </div>
    @include('public._sign-form', ['action' => $bu.'/contracts/'.$contract->id.'/countersign', 'recipient' => $recipient, 'version' => $version, 'consent' => $consent])
</div>
@endsection
