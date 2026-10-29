@extends('layouts.app')
@section('title', 'Clients')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Clients</h1>
    @if ($ctx->can('clients.manage'))<a href="{{ $bu }}/clients/new" class="btn btn-primary">+ New client</a>@endif
</div>
<form method="get" class="mt-4 flex flex-wrap gap-2">
    <label for="q" class="sr-only">Search clients</label>
    <input id="q" name="q" class="input max-w-xs" placeholder="Search name or email" value="{{ request('q') }}">
    <label for="status" class="sr-only">Status</label>
    <select id="status" name="status" class="input w-auto">
        @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach
    </select>
    <button class="btn">Filter</button>
</form>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th class="num">Open balance</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($clients as $c)
            <tr>
                <td><a href="{{ $bu }}/clients/{{ $c->id }}" class="font-medium">{{ $c->display_name }}</a>@if($c->legal_name && $c->legal_name !== $c->display_name)<div class="text-xs text-slate-500">{{ $c->legal_name }}</div>@endif</td>
                <td>{{ $c->email }}</td>
                <td>{{ $c->phone }}</td>
                <td class="num">@money((int) ($balances[$c->id] ?? 0))</td>
                <td>@include('partials.status', ['s' => $c->status])</td>
            </tr>
        @empty
            <tr><td colspan="5"><div class="empty m-4">No clients found. @if($ctx->can('clients.manage'))<a href="{{ $bu }}/clients/new">Add your first client</a>.@endif</div></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $clients->links() }}</div>
@endsection
