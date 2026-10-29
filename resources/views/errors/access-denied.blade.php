@extends('layouts.guest')
@section('title', 'Access denied')
@section('content')
<div class="card card-body">
    <h1>Access denied</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $reason ?? 'You do not have access to this page.' }}</p>
    <a href="/auth/login" class="btn mt-4">Sign in again</a>
</div>
@endsection
