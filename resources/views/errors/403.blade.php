@extends('layouts.guest')
@section('title', 'Not allowed')
@section('content')
<div class="card card-body">
    <h1>Not allowed</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $exception->getMessage() ?: 'Your role does not include permission for this action.' }}</p>
    <a href="/" class="btn mt-4" data-back>Go back</a>
</div>
@endsection
