@extends('layouts.app')
@section('title', 'Statement · '.$client->display_name)
@section('content')
<div class="flex flex-wrap items-end justify-between gap-3">
    <div><h1>Statement</h1><p class="text-sm text-slate-500">{{ $client->display_name }} · {{ $from }} to {{ $to }}</p></div>
    <form method="get" class="flex flex-wrap items-end gap-2">
        <div><label class="label" for="from">From</label><input class="input" type="date" id="from" name="from" value="{{ $from }}"></div>
        <div><label class="label" for="to">To</label><input class="input" type="date" id="to" name="to" value="{{ $to }}"></div>
        <button class="btn">Update</button>
        <a class="btn" href="?from={{ $from }}&to={{ $to }}&format=pdf">PDF</a>
    </form>
</div>
@include('clients._statement-table')
@endsection
