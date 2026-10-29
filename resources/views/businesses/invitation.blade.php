@extends('layouts.guest')
@section('title', 'Invitation · McNutt Finance')
@section('content')
<div class="card card-body">
    <h1>Join {{ $inv->business->name() }}</h1>
    <p class="mt-2 text-sm text-slate-600">You were invited as <strong>{{ $inv->role->name }}</strong>. The invitation was sent to <strong>{{ $inv->email }}</strong>.</p>
    @if ($emailMatches)
        <form method="post" action="/invite/{{ $token }}" class="mt-4">@csrf<button class="btn btn-primary">Accept invitation</button></form>
        @unless (config('finance.auth.email_is_verified'))
            <p class="hint">An owner or admin will approve your access after you accept.</p>
        @endunless
    @else
        <div class="alert alert-warn mt-4">You're signed in as {{ app(\App\Support\Context::class)->user?->email ?? 'another account' }}. Sign in with the McNutt Cloud account for {{ $inv->email }} to accept.</div>
    @endif
</div>
@endsection
