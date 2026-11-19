@extends('layouts.platform')
@section('title', 'Application settings · Server Manager')
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div><h1>Server Manager</h1>
        <p class="mt-1 text-sm text-slate-600">Connect this app to <a href="{{ $managerUrl }}" target="_blank" rel="noopener">{{ parse_url($managerUrl, PHP_URL_HOST) }}</a> for health checks, stats and maintenance. The shared key is generated here automatically and handed to Server Manager during pairing — you never see or copy it.</p></div>
    @if ($paired)<span class="chip chip-green">Paired</span>@elseif ($envSecret)<span class="chip chip-blue">Key set in server environment</span>@else<span class="chip chip-amber">Not paired</span>@endif
</div>

@if ($paired)
<section class="card mt-6">
    <div class="card-head"><h2>Connected</h2></div>
    <div class="card-body space-y-3 text-sm">
        <dl class="dl">
            <dt>Paired</dt><dd>{{ \Illuminate\Support\Carbon::parse($state['paired_at'])->diffForHumans() }} ({{ \Illuminate\Support\Carbon::parse($state['paired_at'])->format('M j, Y g:ia') }})</dd>
            <dt>From IP</dt><dd class="font-mono">{{ $state['paired_ip'] ?? '—' }}</dd>
            <dt>Helper URL</dt><dd class="font-mono">{{ $helperUrl }}</dd>
        </dl>
        <form method="post" action="/platform/server-manager/reset" data-confirm="Reset pairing? The shared key is rotated and Server Manager loses access until you pair again.">@csrf
            <button class="btn btn-danger">Reset pairing</button>
            <p class="hint">Use this to move to a different manager or if the key may have leaked.</p>
        </form>
    </div>
</section>
@else
<section class="card mt-6">
    <div class="card-head"><h2>Pair with Server Manager</h2></div>
    <div class="card-body space-y-5 text-sm">
        <ol class="space-y-4">
            <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-700 text-xs font-semibold text-white">1</span>
                <div class="flex-1"><strong class="block text-slate-900">Get an unlock token</strong>
                    In Server Manager open <strong>Apps → Pair app</strong> and generate an unlock token. Paste it here.
                    <form method="post" action="/platform/server-manager/unlock" class="mt-2 space-y-2">@csrf
                        <label class="sr-only" for="unlock_token">Unlock token</label>
                        <textarea class="input font-mono text-xs" id="unlock_token" name="unlock_token" rows="3" required placeholder="Paste the signed unlock token" autocomplete="off">{{ old('unlock_token') }}</textarea>
                        @error('unlock_token')<p class="field-error">{{ $message }}</p>@enderror
                        <button class="btn btn-primary">Verify token</button>
                    </form></div></li>
            <li class="flex gap-3"><span @class(['flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold', 'bg-brand-700 text-white' => $pending, 'bg-slate-200 text-slate-600' => ! $pending])>2</span>
                <div class="min-w-0 flex-1"><strong class="block text-slate-900">Paste the enrollment payload back into Server Manager</strong>
                    @if ($pending)
                        <div class="mt-2 flex items-start gap-2">
                            <code class="block min-w-0 flex-1 break-all rounded-md border border-slate-200 bg-slate-50 p-3 text-xs">{{ $pending['payload'] }}</code>
                            <button type="button" class="btn btn-sm" data-copy="{{ $pending['payload'] }}">Copy</button>
                        </div>
                        <p class="hint">Single use · expires {{ \Illuminate\Support\Carbon::createFromTimestamp($pending['expires'])->diffForHumans() }}. Server Manager then fetches the key from this app directly over HTTPS.</p>
                    @else
                        <span class="text-slate-500">Appears here after the unlock token is verified.</span>
                    @endif
                </div></li>
            <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">3</span>
                <div><strong class="block text-slate-900">Done</strong>Refresh this page — it shows <em>Paired</em> once Server Manager has connected.</div></li>
        </ol>
        @if ($envSecret)<p class="alert alert-info">A key is set with <code>SRVMGR_HELPER_TOKEN</code> in the server environment; it is used instead of the generated one and is what pairing hands over.</p>@endif
    </div>
</section>
@endif

<form method="post" action="/platform/server-manager/settings" class="card mt-4">@csrf
    <div class="card-head"><h2>Manager</h2></div>
    <div class="card-body grid gap-4 text-sm sm:grid-cols-2">
        <div><label class="label" for="manager_url">Server Manager URL</label><input class="input" id="manager_url" name="manager_url" type="url" required value="{{ old('manager_url', $managerUrl) }}">
            @error('manager_url')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div><label class="label" for="manager_pubkey">Manager public key <span class="font-normal text-slate-500">(optional)</span></label>
            <input class="input font-mono text-xs" id="manager_pubkey" name="manager_pubkey" value="{{ old('manager_pubkey') }}" placeholder="{{ $pubkey ? 'Pinned: '.$pubkey : 'Fetched automatically on first pairing' }}" @disabled($pubkeyFromEnv) autocomplete="off">
            <p class="hint">Used to verify tokens signed by Server Manager. Leave blank to fetch it from the manager and pin it.{{ $pubkeyFromEnv ? ' Set by SRVMGR_MANAGER_PUBKEY.' : '' }}</p>
            @error('manager_pubkey')<p class="field-error">{{ $message }}</p>@enderror</div>
        <div class="sm:col-span-2"><button class="btn">Save</button></div>
    </div>
</form>
@endsection
