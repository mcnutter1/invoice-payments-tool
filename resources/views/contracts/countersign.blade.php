@extends('layouts.app')
@section('title', 'Countersign')
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>Countersign {{ $contract->number }}</h1>
        <p class="text-sm text-slate-500">You're signing on behalf of {{ $biz->name() }}. Your McNutt Cloud sign-in is used as identity verification.</p>
    </div>
</div>
<div class="mt-4 grid gap-4 xl:grid-cols-5">
    <div class="card xl:col-span-3">
        <div class="card-head"><h2>{{ $version->title }}</h2><a href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $version->version }}/document" target="_blank" rel="noopener" class="text-sm">Open PDF</a></div>
        @if ($html)<div class="card-body prose-doc max-h-[40rem] overflow-auto text-sm">{!! $html !!}</div>@else<p class="card-body text-sm">Review the attached PDF before signing.</p>@endif
    </div>
    <div class="space-y-3 xl:col-span-2">
        @include('public._sign-form', ['action' => $bu.'/contracts/'.$contract->id.'/countersign', 'recipient' => $recipient, 'version' => $version, 'consent' => $consent, 'savedSignature' => $savedSignature, 'allowSave' => true])
        @if ($savedSignature)
            <form method="post" action="{{ $bu }}/my-signature/delete" class="text-right" data-confirm="Remove your saved signature?">@csrf<button class="btn btn-ghost btn-sm">Remove saved signature</button></form>
        @endif
    </div>
</div>
@endsection
