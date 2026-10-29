@extends('layouts.app')
@section('title', 'Search')
@section('content')
<h1>Search</h1>
<form method="get" class="mt-4 flex max-w-lg gap-2" role="search">
    <label for="q" class="sr-only">Search</label>
    <input id="q" name="q" class="input" value="{{ $q }}" placeholder="Client name, email, invoice or contract number" autofocus>
    <button class="btn">Search</button>
</form>
<div class="card mt-4">
    <ul class="divide-y divide-slate-100">
        @forelse ($results as $r)
            <li class="px-4 py-2"><span class="chip chip-gray mr-2">{{ $r['type'] }}</span><a href="{{ $r['url'] }}">{{ $r['label'] }}</a></li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-slate-500">{{ mb_strlen($q) >= 2 ? 'No matches.' : 'Type at least two characters.' }}</li>
        @endforelse
    </ul>
</div>
@endsection
