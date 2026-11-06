@extends('layouts.app')
@section('title', 'Roles')
@section('content')
@include('settings._tabs')
<h2 class="text-lg">Roles &amp; permissions</h2>
<p class="mt-1 text-sm text-slate-500">You can only grant or remove permissions you hold yourself. The Owner role is fixed.</p>
@php $groups = collect($catalog)->groupBy(fn ($v) => $v[0], true); @endphp
<div class="mt-4 space-y-4">
    @foreach ($roles as $role)
        <details class="card"><summary class="card-head cursor-pointer"><h2>{{ $role->name }} @if($role->is_system)<span class="chip chip-gray">default</span>@endif</h2><span class="text-xs text-slate-500">{{ $role->permissions->count() }} permissions</span></summary>
            <form method="post" action="{{ $bu }}/settings/roles/{{ $role->id }}" class="card-body space-y-3">@csrf
                <fieldset @disabled($role->key === 'owner') class="space-y-3">
                <input class="input max-w-xs" name="name" value="{{ $role->name }}" aria-label="Role name">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($groups as $group => $perms)
                        <fieldset><legend class="text-xs font-semibold uppercase text-slate-500">{{ $group }}</legend>
                            @foreach ($perms as $key => [$g, $desc])
                                <label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="permissions[]" value="{{ $key }}" @checked($role->permissions->contains('key', $key)) @disabled(! $ctx->can($key) || $key === 'ownership.transfer')> <span>{{ $desc }} <code class="text-[10px] text-slate-400">{{ $key }}</code></span></label>
                            @endforeach
                        </fieldset>
                    @endforeach
                </div>
                @unless ($role->key === 'owner')<button class="btn btn-primary">Save role</button>@endunless
                </fieldset>
            </form>
        </details>
    @endforeach
    <details class="card"><summary class="card-head cursor-pointer"><h2>+ Custom role</h2></summary>
        <form method="post" action="{{ $bu }}/settings/roles" class="card-body space-y-3">@csrf
            <input class="input max-w-xs" name="name" placeholder="Role name" required aria-label="Role name">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups as $group => $perms)
                    <fieldset><legend class="text-xs font-semibold uppercase text-slate-500">{{ $group }}</legend>
                        @foreach ($perms as $key => [$g, $desc])
                            @if ($key !== 'ownership.transfer')<label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="permissions[]" value="{{ $key }}" @disabled(! $ctx->can($key))> <span>{{ $desc }}</span></label>@endif
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            <button class="btn btn-primary">Create role</button>
        </form>
    </details>
</div>
@endsection
