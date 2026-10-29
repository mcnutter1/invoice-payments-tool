@extends('layouts.guest')
@section('title', 'Platform operations')
@section('content')
<h1>Platform operations</h1>
<p class="mt-1 text-sm text-slate-600">Operational settings only. Nothing here grants access to any business's books.</p>
<div class="mt-6 grid gap-4 sm:grid-cols-2">
    @foreach (['Auth (McNutt Cloud)' => $auth, 'Notifications' => $notify] as $label => $h)
        <div class="card card-body">
            <div class="text-sm font-medium">{{ $label }}</div>
            <span class="chip {{ $h['status'] === 'healthy' ? 'chip-green' : ($h['status'] === 'failed' ? 'chip-red' : 'chip-amber') }} mt-2">{{ ucfirst($h['status']) }}</span>
            <p class="hint">{{ $h['message'] }}</p>
        </div>
    @endforeach
</div>
<div class="card mt-6">
    <div class="card-head"><h2>Central role → capability mappings</h2></div>
    <div class="card-body">
        <table class="tbl"><thead><tr><th>Central role</th><th>Capability</th><th></th></tr></thead><tbody>
        @forelse ($mappings as $m)
            <tr><td>{{ $m->central_role }}</td><td>{{ $m->capability }}</td>
                <td class="text-right"><form method="post" action="/platform/role-mappings/{{ $m->id }}/delete" data-confirm="Remove this mapping?">@csrf<button class="btn btn-sm btn-danger">Remove</button></form></td></tr>
        @empty
            <tr><td colspan="3" class="text-slate-500">No mappings. Central admin roles do not grant tenant access.</td></tr>
        @endforelse
        </tbody></table>
        <form method="post" action="/platform/role-mappings" class="mt-4 flex flex-wrap items-end gap-2">
            @csrf
            <div><label class="label" for="central_role">Central role</label><input class="input" id="central_role" name="central_role" required></div>
            <div><label class="label" for="capability">Capability</label>
                <select class="input" id="capability" name="capability">@foreach ($capabilities as $k => $v)<option value="{{ $k }}">{{ $k }} — {{ $v }}</option>@endforeach</select></div>
            <button class="btn btn-primary">Add mapping</button>
        </form>
    </div>
</div>
@endsection
