@extends('layouts.public', ['accent' => $accent])
@section('title', 'Form W-9 — '.$business->name())
@section('content')
<div class="card card-body text-center">
    @if ($logo)<img src="{{ $logo }}" alt="{{ $business->name() }}" class="mx-auto mb-3 max-h-14">@endif
    <h1>Form W-9 from {{ $business->name() }}</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $business->name() }} has shared its completed IRS Form W-9 with you.</p>
    <form method="post" action="/w9/{{ $raw }}" class="mt-5">@csrf
        <button class="btn btn-primary btn-lg">Download W-9 (PDF)</button>
    </form>
    <p class="hint mt-4">This link is for {{ $token->recipient_email }} and is available until {{ $token->expires_at->format('F j, Y') }}@if ($remaining !== null) ({{ $remaining }} download{{ $remaining === 1 ? '' : 's' }} left)@endif. The PDF can be printed but not edited. Please store it securely — it contains a taxpayer identification number.</p>
</div>
@endsection
