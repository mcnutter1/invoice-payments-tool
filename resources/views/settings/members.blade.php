@extends('layouts.app')
@section('title', 'Members')
@section('content')
<h1>Members</h1>
<p class="mt-1 text-sm text-slate-500">Members sign in with McNutt Cloud. Central admin roles never grant access to this business's books — only membership here does.</p>
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Member</th><th>Roles</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($members as $m)
        <tr>
            <td><div class="font-medium">{{ $m->user->label() }}</div><div class="text-xs text-slate-500">{{ $m->user->email }} · {{ $m->user->central_principal_id }}</div></td>
            <td>
                <form method="post" action="{{ $bu }}/settings/members/{{ $m->id }}/roles" class="flex flex-wrap items-center gap-2">@csrf
                    @foreach ($roles as $r)
                        <label class="inline-flex items-center gap-1 text-xs"><input class="check" type="checkbox" name="roles[]" value="{{ $r->id }}" @checked($m->roles->contains('id', $r->id)) @disabled($r->key === 'owner' && ! $isOwner)> {{ $r->name }}</label>
                    @endforeach
                    <button class="btn btn-sm">Save</button>
                </form>
                <p class="mt-1 text-xs text-slate-500">Source: business membership{{ $m->invited_by ? ' (invited)' : '' }}</p>
            </td>
            <td>@include('partials.status', ['s' => $m->status])</td>
            <td class="text-right">
                @if ($m->status === 'pending_approval')<form method="post" action="{{ $bu }}/settings/members/{{ $m->id }}/approve" class="inline">@csrf<button class="btn btn-sm btn-primary">Approve</button></form>@endif
                @if ($m->user_id !== $ctx->user->id)<form method="post" action="{{ $bu }}/settings/members/{{ $m->id }}/remove" class="inline" data-confirm="Remove {{ $m->user->label() }}? Access ends immediately.">@csrf<button class="btn btn-sm btn-danger">Remove</button></form>@endif
            </td>
        </tr>
    @endforeach
    </tbody></table>
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <form method="post" action="{{ $bu }}/settings/members/invite" class="card card-body space-y-2">@csrf
        <h2>Invite a member</h2>
        <label class="label" for="inv-email">Email</label><input class="input" id="inv-email" name="email" type="email" required>
        <label class="label" for="inv-role">Role</label><select class="input" id="inv-role" name="role_id">@foreach ($roles as $r)@if($r->key !== 'owner' || $isOwner)<option value="{{ $r->id }}" @selected($r->key === 'viewer')>{{ $r->name }}</option>@endif @endforeach</select>
        <button class="btn btn-primary">Send invitation</button>
        <p class="hint">Single-use link, expires in 7 days. {{ config('finance.auth.email_is_verified') ? '' : 'Because McNutt Cloud does not mark emails as verified, you will approve the member after they accept.' }}</p>
    </form>
    <div class="card"><div class="card-head"><h2>Pending invitations</h2></div><ul class="card-body space-y-2 text-sm">
        @forelse ($invitations as $i)<li class="flex justify-between"><span>{{ $i->email }} · {{ $i->role->name }} · expires {{ $i->expires_at->format('M j') }}</span>
            <form method="post" action="{{ $bu }}/settings/invitations/{{ $i->id }}/revoke">@csrf<button class="btn btn-ghost btn-sm">Revoke</button></form></li>
        @empty<li class="text-slate-500">None.</li>@endforelse
    </ul></div>
</div>
@if ($ctx->can('ownership.transfer'))
<form method="post" action="{{ $bu }}/settings/ownership" class="card card-body mt-4 space-y-2 border-red-200">@csrf
    <h2>Transfer ownership</h2>
    <select class="input" name="membership_id" aria-label="New owner">@foreach ($members->where('status', 'active')->where('user_id', '!=', $ctx->user->id) as $m)<option value="{{ $m->id }}">{{ $m->user->label() }}</option>@endforeach</select>
    <label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="keep_admin" value="1" checked> Keep me as Admin</label>
    <label class="label" for="confirm">Type TRANSFER to confirm</label><input class="input w-48" id="confirm" name="confirm" required autocomplete="off">
    <div><button class="btn btn-danger">Transfer ownership</button></div>
</form>
@endif
@endsection
