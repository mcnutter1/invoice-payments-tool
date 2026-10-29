@extends('layouts.guest')
@section('title', 'Sign-in service unavailable')
@section('content')
<div class="card card-body">
    <h1>Sign-in service unavailable</h1>
    <p class="mt-2 text-sm text-slate-600">We could not confirm your session with McNutt Cloud, so access is paused to keep financial data safe. Please try again in a few minutes.</p>
    <a href="/" class="btn mt-4">Try again</a>
</div>
@endsection
