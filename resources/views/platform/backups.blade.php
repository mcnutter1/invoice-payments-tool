@extends('layouts.platform')
@section('title', 'Application settings · Backups')
@section('content')
@php $fmt = fn ($n) => \App\Models\BackupSnapshot::humanBytes($n); $fresh = $heartbeat?->last_success_at && \Illuminate\Support\Carbon::parse($heartbeat->last_success_at)->gt(now()->subHours(2)); @endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <div><h1>Backups</h1><p class="mt-1 text-sm text-slate-600">Backups for every business: hourly deltas and a daily full, stored in your S3 bucket and partitioned per business.</p></div>
</div>

<div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="metric"><div class="metric-label">Businesses backed up</div><div class="metric-value">{{ $totals['enabled'] }} / {{ $businesses->count() }}</div></div>
    <div class="metric"><div class="metric-label">Backup points</div><div class="metric-value">{{ number_format($totals['snapshots']) }}</div></div>
    <div class="metric"><div class="metric-label">Storage used</div><div class="metric-value">{{ $fmt($totals['bytes']) }}</div><div class="metric-note">≈ ${{ number_format($monthly, 2) }}/month storage (estimate)</div></div>
    <div class="metric"><div class="metric-label">Last 24 hours</div><div class="metric-value text-lg">{{ $totals['ok24'] }} ok @if($totals['failed24'])<span class="text-red-700">· {{ $totals['failed24'] }} failed</span>@endif</div>
        <div class="metric-note">Runner: @if (! $configured) not configured @elseif ($fresh) healthy @else <span class="text-red-700">no recent run</span> @endif</div></div>
</div>

<form method="post" action="/platform/backups/settings" class="card mt-6">@csrf
    <div class="card-head"><h2>S3 storage</h2>
        <span class="chip {{ $configured ? 'chip-green' : 'chip-amber' }}">{{ $configured ? 'Configured' : 'Not configured' }}</span></div>
    <div class="card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="bucket">Bucket</label><input class="input" id="bucket" name="bucket" value="{{ old('bucket', $cfg['bucket']) }}" required placeholder="acme-finance-backups"></div>
        <div><label class="label" for="region">Region</label><input class="input" id="region" name="region" value="{{ old('region', $cfg['region']) }}" required list="aws-regions">
            <datalist id="aws-regions">@foreach (\App\Services\Backups\BackupSettings::regions() as $r)<option value="{{ $r }}">@endforeach</datalist></div>
        <div><label class="label" for="access_key_id">Access key ID</label><input class="input" id="access_key_id" name="access_key_id" value="{{ old('access_key_id', $cfg['access_key_id']) }}" autocomplete="off" required placeholder="AKIA…"></div>
        <div><label class="label" for="secret_access_key">Secret access key</label><input class="input" id="secret_access_key" name="secret_access_key" type="password" autocomplete="new-password" placeholder="{{ $hasSecret ? 'Saved — leave blank to keep' : 'Required' }}"><p class="hint">Stored encrypted; never shown again.</p></div>
        <div><label class="label" for="prefix">Key prefix</label><input class="input" id="prefix" name="prefix" value="{{ old('prefix', $cfg['prefix']) }}"><p class="hint">Objects are written under <code>{prefix}/businesses/{business id}/…</code></p></div>
        <div><label class="label" for="endpoint">Custom endpoint (optional)</label><input class="input" id="endpoint" name="endpoint" type="url" value="{{ old('endpoint', $cfg['endpoint']) }}" placeholder="https://… for S3-compatible storage">
            <label class="mt-1 flex items-center gap-2 text-xs"><input type="hidden" name="path_style" value="0"><input class="check" type="checkbox" name="path_style" value="1" @checked($cfg['path_style'])> Path-style URLs</label></div>

        <fieldset class="sm:col-span-2"><legend class="label">Storage classes (lowest cost that still restores instantly)</legend>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (['class_delta' => 'Hourly deltas', 'class_full' => 'Daily fulls', 'class_files' => 'Files (PDFs, images)'] as $field => $label)
                    <div><label class="text-xs text-slate-600" for="{{ $field }}">{{ $label }}</label>
                        <select class="input" id="{{ $field }}" name="{{ $field }}">
                            @foreach (\App\Services\Backups\BackupSettings::STORAGE_CLASSES as $k => $sc)
                                <option value="{{ $k }}" @selected($cfg[$field] === $k)>{{ $sc['label'] }} (~${{ $sc['price'] }}/GB-mo)</option>
                            @endforeach
                        </select></div>
                @endforeach
            </div>
            <p class="hint mt-2">Recommended: <strong>Standard</strong> for hourly deltas (tiny files deleted within days — cold classes would bill a 30–90 day minimum and a 128 KB minimum per object), and <strong>Glacier Instant Retrieval</strong> for daily fulls and files (about 80% cheaper than Standard, still readable in milliseconds). Glacier Flexible Retrieval and Deep Archive aren’t offered: they need hours to thaw, which would break the explorer, previews and one-click restores. Glacier IR bills at least 90 days per object, so shorter full retention doesn’t lower cost below that.</p>
        </fieldset>

        <div><label class="label" for="delta_retention_days">Keep hourly deltas (days)</label><input class="input" id="delta_retention_days" name="delta_retention_days" type="number" min="1" max="90" value="{{ $cfg['delta_retention_days'] }}"></div>
        <div><label class="label" for="full_retention_days">Keep daily fulls (days)</label><input class="input" id="full_retention_days" name="full_retention_days" type="number" min="1" max="3650" value="{{ $cfg['full_retention_days'] }}"></div>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="hidden" name="default_enabled" value="0"><input class="check" type="checkbox" name="default_enabled" value="1" @checked($cfg['default_enabled'])> Turn on backups automatically for new businesses</label>

        <fieldset class="space-y-2 sm:col-span-2"><legend class="label">Notifications</legend>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="notify_failures" value="0"><input class="check" type="checkbox" name="notify_failures" value="1" @checked($cfg['notify_failures'])> Email immediately when a backup fails (repeated at most daily while it keeps failing) and when it recovers</label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="notify_weekly" value="0"><input class="check" type="checkbox" name="notify_weekly" value="1" @checked($cfg['notify_weekly'])> Weekly summary every Monday, including an automatic restore test of each business’s latest backup</label>
            <div><label class="text-xs text-slate-600" for="notify_emails">Send to</label><input class="input" id="notify_emails" name="notify_emails" value="{{ old('notify_emails', $cfg['notify_emails']) }}" placeholder="ops@example.com, owner@example.com">
                <p class="hint">Blank = every user with the SSO admin role{{ $recipients ? ' (currently '.implode(', ', $recipients).')' : '' }}.</p></div>
        </fieldset>

        <p class="hint sm:col-span-2">Each object is compressed and encrypted with the application key before upload and also stored with S3 server-side encryption. Keep the application key (in the server's env backup) safe — backups can't be read without it.</p>
        <div class="flex flex-wrap gap-2 sm:col-span-2"><button class="btn btn-primary">Save settings</button></div>
    </div>
</form>

<div class="card mt-4"><div class="card-head"><h2>Checks</h2>
    <div class="flex flex-wrap gap-2">
        <form method="post" action="/platform/backups/test">@csrf<button class="btn btn-sm" @disabled(! $configured)>Test connection &amp; restore</button></form>
        <form method="post" action="/platform/backups/notify-test">@csrf<button class="btn btn-sm">Send test notification</button></form>
    </div></div>
    <div class="card-body text-sm">
        @if ($lastTest)
            <p class="mb-2 text-xs text-slate-500">Last run {{ \Illuminate\Support\Carbon::parse($lastTest['at'])->diffForHumans() }} —
                <span class="chip {{ $lastTest['ok'] ? 'chip-green' : 'chip-red' }}">{{ $lastTest['ok'] ? 'all passed' : 'failed' }}</span></p>
            <ul class="space-y-1">
                @foreach ($lastTest['steps'] as $s)
                    <li><span class="chip {{ $s['ok'] === null ? 'chip-gray' : ($s['ok'] ? 'chip-green' : 'chip-red') }}">{{ $s['ok'] === null ? 'skipped' : ($s['ok'] ? 'pass' : 'fail') }}</span>
                        <span class="font-medium">{{ $s['name'] }}</span> <span class="text-slate-600">— {{ $s['detail'] }}</span></li>
                @endforeach
            </ul>
        @else
            <p class="text-slate-500">Not run yet. The test writes with each storage class, confirms encryption, reads back, deletes, then downloads and rebuilds the latest backup.</p>
        @endif
    </div>
</div>

<details class="card mt-4">
    <summary class="card-head cursor-pointer list-none"><h2>AWS setup guide</h2><span class="text-xs text-slate-500">Show</span></summary>
    <div class="card-body space-y-4 text-sm">
        <ol class="list-decimal space-y-1 pl-5">
            <li><strong>Create a private bucket</strong> in the region closest to your server. Keep <em>Block all public access</em> on, enable <em>default encryption (SSE-S3)</em>. Versioning is optional (adds protection against deletion, but deleted backups then keep costing until a lifecycle rule removes old versions).</li>
            <li><strong>Add the bucket policy</strong> below (refuses any non-HTTPS access).</li>
            <li><strong>Add the lifecycle rules</strong> below (cleans up interrupted uploads and connection-test objects). Don’t add transition rules to Glacier Flexible/Deep Archive — the app chooses storage classes itself and needs instant reads.</li>
            <li><strong>Create an IAM user</strong> (e.g. <code>mcnutt-finance-backups</code>) with only the inline policy below — it can read, write and delete objects under <code>{{ $cfg['prefix'] ?: 'mcnutt-finance' }}/</code> in this bucket and nothing else. Create an access key for it and paste it above.</li>
            <li>Click <strong>Test connection &amp; restore</strong>, then enable businesses below. The first run creates a full backup; after that, hourly deltas.</li>
        </ol>
        @foreach (['iam' => 'IAM user policy (iam-policy.json)', 'bucket' => 'Bucket policy (bucket-policy.json)', 'lifecycle' => 'Lifecycle rules (lifecycle.json)', 'cli' => 'Or with the AWS CLI'] as $k => $title)
            <div>
                <div class="flex items-center justify-between"><h3 class="text-sm font-semibold">{{ $title }}</h3><button type="button" class="btn btn-sm" data-copy="{{ $policies[$k] }}">Copy</button></div>
                <pre class="mt-1 max-h-72 overflow-auto rounded-md bg-slate-50 p-3 text-xs">{{ $policies[$k] }}</pre>
            </div>
        @endforeach
        <p class="hint">Cost guide (us-east-1, approximate): Glacier IR ≈ $0.004/GB-month, Standard ≈ $0.023/GB-month, plus ~$0.02 per 1,000 Glacier IR uploads and $0.03/GB to read Glacier IR (only when restoring, previewing or testing). Hourly deltas are kept local-first: the runner never reads from S3 during normal backups.</p>
    </div>
</details>

<form method="post" action="/platform/backups/businesses" class="card mt-6">@csrf
    <div class="card-head"><h2>Businesses</h2><button class="btn btn-sm btn-primary">Save selection</button></div>
    <div class="overflow-x-auto"><table class="tbl">
        <thead><tr><th>Back up</th><th>Business</th><th>Last backup</th><th>Last full</th><th>Restore test</th><th class="text-right">Points</th><th class="text-right">Size</th><th></th></tr></thead>
        <tbody>
        @foreach ($businesses as $b)
            @php $l = $latest[$b->id] ?? null; $pb = $perBusiness[$b->id] ?? null; $f = $files[$b->id] ?? null; @endphp
            <tr>
                <td><input class="check" type="checkbox" name="enabled[]" value="{{ $b->id }}" @checked($b->backups_enabled) aria-label="Back up {{ $b->name() }}"></td>
                <td><span class="font-medium">{{ $b->name() }}</span> @if($b->status !== 'active')<span class="chip chip-gray">{{ $b->status }}</span>@endif</td>
                <td class="text-xs">@if ($l){{ $l->started_at->diffForHumans() }} · {{ $l->type }} <span class="chip {{ $l->status === 'completed' ? 'chip-green' : ($l->status === 'failed' ? 'chip-red' : 'chip-amber') }}">{{ $l->status }}</span>@else <span class="text-slate-500">Never</span>@endif</td>
                <td class="text-xs">{{ ($lastFull[$b->id] ?? null)?->started_at->diffForHumans() ?? '—' }}</td>
                <td class="text-xs">@php $v = $verified[$b->id] ?? null; @endphp
                    @if ($v)<span class="chip {{ $v->verify_error ? 'chip-red' : 'chip-green' }}" title="{{ $v->verify_error }}">{{ $v->verify_error ? 'failed' : 'passed' }}</span> {{ $v->verified_at->diffForHumans() }}@else <span class="text-slate-500">Not yet</span>@endif</td>
                <td class="text-right">{{ $pb->snapshots ?? 0 }}</td>
                <td class="text-right text-xs">{{ $fmt(($pb->bytes ?? 0) + ($f->bytes ?? 0)) }}@if($f)<br><span class="text-slate-500">{{ $f->files }} files</span>@endif</td>
                <td class="text-right"><button class="btn btn-sm" formaction="/platform/backups/businesses/{{ $b->ulid }}/run" @disabled(! $configured)>Back up now</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</form>

@if ($failures->isNotEmpty())
<div class="card mt-6"><div class="card-head"><h2>Recent failures</h2></div>
    <ul class="card-body space-y-1 text-sm">
        @foreach ($failures as $s)<li><span class="font-medium">{{ $s->business?->name() }}</span> · {{ $s->started_at->diffForHumans() }} · <span class="text-red-700">{{ \Illuminate\Support\Str::limit($s->error, 200) }}</span></li>@endforeach
    </ul></div>
@endif
<p class="hint mt-4">Administrators see backup health and sizes only. Browsing and restoring a business's data happens inside that business (Settings → Backups) and requires access to it.</p>
@endsection
