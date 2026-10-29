@extends('layouts.guest')
@section('title', 'Your businesses · McNutt Finance')
@section('content')
<div class="flex items-center justify-between">
    <h1>Choose a business</h1>
    @if ($canCreate)<a href="/businesses/new" class="btn btn-primary">+ Create business</a>@endif
</div>
<p class="mt-1 text-sm text-slate-600">Each business keeps separate books, clients, documents and members.</p>
<div class="mt-6 space-y-2">
    @forelse ($memberships as $m)
        @if ($m->status === 'active')
            <a href="/b/{{ $m->business->ulid }}" class="card card-body flex items-center justify-between no-underline hover:border-brand-300">
                <span>
                    <span class="block font-semibold text-slate-900">{{ $m->business->name() }}</span>
                    <span class="block text-xs text-slate-500">{{ $m->business->legal_name }}</span>
                </span>
                <span class="chip chip-gray">{{ $m->roles->pluck('name')->implode(', ') }}</span>
            </a>
        @else
            <div class="card card-body flex items-center justify-between opacity-75">
                <span class="font-semibold">{{ $m->business->name() }}</span>
                <span class="chip chip-amber">Awaiting owner approval</span>
            </div>
        @endif
    @empty
        <div class="empty">
            You are not a member of any business yet.
            @if ($canCreate) <a href="/businesses/new">Create your first business</a>. @else Ask an owner to invite you. @endif
        </div>
    @endforelse
</div>
@endsection
