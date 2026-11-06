@extends('layouts.public')
@section('title', 'W-9 link not available')
@section('content')
<div class="card card-body text-center">
    <h1>This W-9 link is no longer available</h1>
    <p class="mt-2 text-sm text-slate-600">W-9 links expire after a short time or a few downloads to keep tax information private.
        @php $contact = $business?->public_email ? ' at '.$business->public_email : ''; @endphp
        Please ask {{ $business?->name() ?? 'the business that sent it' }} for a new copy{{ $contact }}.</p>
</div>
@endsection
