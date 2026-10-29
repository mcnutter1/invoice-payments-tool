@extends('layouts.guest')
@section('title', 'Something went wrong')
@section('content')
<div class="card card-body">
    <h1>Something went wrong</h1>
    <p class="mt-2 text-sm text-slate-600">The error was logged. Nothing was partially saved — financial changes are all-or-nothing. Please try again.</p>
    <a href="/" class="btn mt-4">Home</a>
</div>
@endsection
