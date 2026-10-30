<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 50px 44px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #111827; }
    h1 { font-size: 16px; margin: 0 0 4px; } h2 { font-size: 11px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
    table { width: 100%; border-collapse: collapse; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
    th, td { text-align: left; padding: 4px 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; } th { background: #f3f4f6; font-size: 8px; }
    .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8px; word-break: break-all; } .muted { color: #6b7280; }
    .box { border: 1px solid #d1d5db; padding: 8px; margin-top: 8px; }
</style></head><body>
<h1>Signature certificate</h1>
<div class="muted">{{ $business->legal_name }} · {{ $contract->number }} version {{ $version->version }} · “{{ $version->title }}” · Generated {{ now()->utc()->format('Y-m-d H:i:s') }} UTC</div>

<h2>Document integrity</h2>
<table>
    <tr><td style="width: 30%">Original (unsigned) document SHA-256</td><td class="mono">{{ $original_sha256 }}</td></tr>
    <tr><td>Final signed document SHA-256</td><td class="mono">{{ $signed_sha256 }}</td></tr>
    <tr><td>Status</td><td>Completed — all required signers signed</td></tr>
</table>

<h2>Signers</h2>
<table>
    <thead><tr><th>Signer</th><th>Role</th><th>Verification</th><th>Signed (UTC)</th><th>IP / device</th><th>Signature hash</th></tr></thead>
    <tbody>
    @foreach ($recipients as $r)
        @if ($r->role !== 'cc')
        <tr><td>{{ $r->typed_name ?? $r->name }}{{ $r->typed_title ? ', '.$r->typed_title : '' }}<br><span class="muted">{{ $r->email }}</span></td>
            <td>{{ $r->role === 'countersigner' ? 'Business countersigner' : 'Client signer' }}</td>
            <td>{{ $r->verificationLabel() }}</td>
            <td>{{ $r->signed_at?->utc()->format('Y-m-d H:i:s') }}</td>
            <td>{{ $r->ip }}<br><span class="muted">{{ \Illuminate\Support\Str::limit($r->user_agent, 80) }}</span></td>
            <td class="mono">{{ $r->signature_hash }}</td></tr>
        @endif
    @endforeach
    </tbody>
</table>

<h2>Event log</h2>
<table>
    <thead><tr><th>#</th><th>UTC time</th><th>Event</th><th>Recipient</th><th>IP</th><th>Document hash at event</th></tr></thead>
    <tbody>
    @foreach ($events as $e)
        <tr><td>{{ $e->seq }}</td><td>{{ $e->created_at->utc()->format('Y-m-d H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td>
            <td>{{ optional($recipients->firstWhere('id', $e->contract_recipient_id))->email }}</td><td>{{ $e->ip }}</td><td class="mono">{{ $e->document_sha256 }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Electronic records consent (version {{ $consent_version }})</h2>
<div class="box">{{ $consent_text }}</div>

<h2>Limitations</h2>
<div class="box muted">
    Email verification confirms access to the recipient's inbox at signing time; it does not verify legal identity. SHA-256 values are application-level integrity evidence,
    not a certificate-based digital signature or trusted timestamp. Records are stored with append-only application permissions and encrypted backups;
    a system administrator with direct server access could still alter stored data. IP addresses are recorded as received through the configured proxy chain.
</div>
</body></html>
