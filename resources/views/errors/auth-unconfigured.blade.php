@extends('layouts.guest')
@section('title', 'Sign-in not configured')
@section('content')
<div class="card card-body">
    <h1>Sign-in is not configured yet</h1>
    <p class="mt-2 text-sm text-slate-600">The operator must set <code>AUTH_APP_ID</code> and <code>AUTH_APP_SECRET</code> for this application's McNutt Cloud registration. See docs/AUTH_INTEGRATION.md.</p>
</div>
@endsection
