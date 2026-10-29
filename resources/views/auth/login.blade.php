@extends('layouts.guest')
@section('title', 'Sign in · McNutt Finance')
@section('content')
<div class="card card-body max-w-md">
    <h1>Sign in</h1>
    <p class="mt-2 text-sm text-slate-600">McNutt Finance uses your McNutt Cloud account. You will return here after signing in.</p>
    <a href="{{ $loginUrl }}" class="btn btn-primary mt-6 w-full">Continue with McNutt Cloud</a>
</div>
@endsection
