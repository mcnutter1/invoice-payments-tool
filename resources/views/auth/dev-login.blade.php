@extends('layouts.guest')
@section('title', 'Development sign-in')
@section('content')
<div class="card card-body max-w-md">
    <h1>Development sign-in</h1>
    <p class="alert alert-warn mt-3">Local development only. This form is disabled in production.</p>
    <form method="post" action="/auth/dev-login" class="mt-4 space-y-3">
        @csrf
        <div><label class="label" for="uid">Principal ID</label><input class="input" id="uid" name="uid" value="demo_owner" required></div>
        <div><label class="label" for="name">Name</label><input class="input" id="name" name="name" value="Dana Owner" required></div>
        <div><label class="label" for="email">Email</label><input class="input" id="email" name="email" type="email" value="owner@example.test" required></div>
        <button class="btn btn-primary w-full">Sign in</button>
    </form>
</div>
@endsection
