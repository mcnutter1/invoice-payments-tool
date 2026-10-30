@extends('layouts.app')
@section('title', $contract->number)
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $contract->number }} · {{ $contract->title }} @include('partials.status', ['s' => $contract->status])</h1>
        <p class="text-sm text-slate-500"><a href="{{ $bu }}/clients/{{ $contract->client_id }}">{{ $contract->client->display_name }}</a>
            @if ($contract->effective_date) · Effective {{ $contract->effective_date->format('M j, Y') }} @endif
            @if ($term = $contract->termLabel()) · Term {{ $term }} @endif
            @if ($contract->terminated_at) · <span class="text-red-700">Terminated {{ $contract->terminated_at->format('M j, Y') }}</span> @endif
            @if ($contract->externally_signed) · <span class="chip chip-gray">Externally signed — no in-app signature trail</span> @endif
            @if ($contract->renewed_from_id) · Renewal of <a href="{{ $bu }}/contracts/{{ $contract->renewed_from_id }}">{{ $contract->renewedFrom?->number }}</a> @endif
            @foreach ($renewals as $rn) · Renewed as <a href="{{ $bu }}/contracts/{{ $rn->id }}">{{ $rn->number }}</a> @endforeach</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($myCountersign)<a class="btn btn-primary" href="{{ $bu }}/contracts/{{ $contract->id }}/countersign">{{ $current->status === 'prepared' ? 'Sign before sending' : 'Countersign now' }}</a>@endif
        @if ($current && ! $current->source_document_id)<a class="btn" href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $current->version }}/preview" target="_blank" rel="noopener">Preview contract</a>@endif
        @if ($current && in_array($current->status, ['draft']) && $ctx->can('contracts.manage'))
            <a class="btn" href="{{ $bu }}/contracts/{{ $contract->id }}/edit">Edit draft</a>
            <a class="btn" href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $current->version }}/document" target="_blank" rel="noopener">Preview PDF</a>
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/prepare" data-confirm="Lock this version? After this it can only be sent or voided.">@csrf<button class="btn btn-primary" @disabled(count($missing))>Lock &amp; prepare</button></form>
        @endif
        @if ($current && $current->status === 'prepared' && $ctx->can('contracts.send'))
            <a class="btn" href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $current->version }}/document" target="_blank" rel="noopener">Review PDF</a>
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/send" data-confirm="Send for signature to all eligible signers?">@csrf<button class="btn btn-primary">Send for signature</button></form>
        @endif
        @if ($current && $current->status === 'completed' && $ctx->can('contracts.manage'))
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/amend" data-confirm="Start an amendment? The signed agreement stays unchanged.">@csrf<button class="btn">Amend</button></form>
        @endif
        @if ($current && in_array($current->status, ['voided', 'declined', 'expired']) && $ctx->can('contracts.manage'))
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/new-version">@csrf<button class="btn btn-primary">Create new version</button></form>
        @endif
        @if ($ctx->can('contracts.manage') && $contract->wasExecuted() && $renewals->isEmpty())
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/renew" data-confirm="Start a renewal? A new draft agreement is created for the next term; this one stays as the signed record.">@csrf<button class="btn {{ $contract->isEnded() ? 'btn-primary' : '' }}">Renew</button></form>
        @endif
        @if ($ctx->can('invoices.manage') && $contract->isExecuted())<a class="btn" href="{{ $bu }}/invoices/new?client={{ $contract->client_id }}">New invoice</a>@endif
    </div>
</div>
@if ($missing)<div class="alert alert-warn mt-4">Fill in missing merge fields before locking: {{ implode(', ', $missing) }}</div>@endif
@if ($contract->isEnded())
    <div class="alert alert-warn mt-4">
        @if ($contract->terminated_at)This agreement was terminated on {{ $contract->terminated_at->format('F j, Y') }}{{ $contract->termination_reason ? ' ('.$contract->termination_reason.')' : '' }}.
        @else This agreement’s term ended on {{ $contract->term_end_date?->format('F j, Y') }}.@endif
        Contract billing is paused. <strong>Renew</strong> to sign a new term, or <strong>Reactivate</strong> to extend this one.
    </div>
@endif
@if ($current && $current->status === 'prepared' && ! $current->recipients->contains('role', 'countersigner') && $ctx->can('contracts.countersign'))
    <div class="alert alert-info mt-4">To sign this agreement for your business, it needs a business countersigner. The locked version can’t change: void it, create a new version, choose yourself as <strong>Business countersigner</strong>, then lock again.</div>
@endif

<div class="mt-6 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="metric"><div class="metric-label">Committed</div><div class="metric-value text-lg">@money($contract->committed_value_cents)</div></div>
            <div class="metric"><div class="metric-label">Invoiced</div><div class="metric-value text-lg">@money($invoiced)</div><div class="metric-note">Issued, non-void</div></div>
            <div class="metric"><div class="metric-label">Collected</div><div class="metric-value text-lg">@money($collected)</div><div class="metric-note">Confirmed payments</div></div>
            <div class="metric"><div class="metric-label">Unbilled</div><div class="metric-value text-lg">@money(max(0, $contract->committed_value_cents - $invoiced))</div><div class="metric-note">Not revenue</div></div>
        </div>
        @if ($current && ! $current->source_document_id)
        <div class="card"><div class="card-head"><h2>Version {{ $current->version }} @if($current->amends_version_id)<span class="chip chip-violet">Amendment</span>@endif</h2>@include('partials.status', ['s' => $current->status])</div>
            <div class="card-body prose-doc max-h-[32rem] overflow-auto text-sm">{!! $preview !!}</div></div>
        @endif
        @php
            $hasPlan = $contract->milestones->isNotEmpty() || $contract->billing_monthly_cents > 0;
            $planOutstanding = $contract->milestones->contains(fn ($m) => $m->status === 'pending' && ! $m->invoice_id)
                || ($contract->billing_monthly_cents > 0 && ! $contract->schedules->contains('origin', 'contract'));
        @endphp
        @if ($hasPlan)
        <div class="card overflow-x-auto"><div class="card-head"><div><h2>Billing plan</h2>
                <p class="text-xs text-slate-500">{{ $contract->billing_auto ? 'Invoices are created automatically when fully signed · '.(\App\Services\Contracts\ContractBillingService::MODES[$contract->billing_mode ?? 'draft'] ?? '') : 'Automatic invoicing is off' }}</p></div>
                @if ($planOutstanding && $contract->isExecuted() && $ctx->can('invoices.manage'))
                    <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/billing/run">@csrf<button class="btn btn-primary btn-sm">Create invoices now</button></form>
                @endif
            </div>
            <table class="tbl"><thead><tr><th>Item</th><th>Invoice date</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
            @if ($contract->billing_monthly_cents > 0)
                @php $sched = $contract->schedules->firstWhere('origin', 'contract'); @endphp
                <tr><td><div class="font-medium">{{ $contract->billing_monthly_description ?: 'Monthly fee' }}</div><div class="text-xs text-slate-500">Monthly{{ $contract->term_end_date ? ' until '.$contract->term_end_date->format('M j, Y') : ', ongoing' }}</div></td>
                    <td>{{ ($contract->billing_start_date ?? $contract->term_start_date ?? $contract->effective_date)?->format('M j, Y') ?? 'On signing' }}</td>
                    <td class="num">@money($contract->billing_monthly_cents)<span class="text-xs text-slate-500">/mo</span></td>
                    <td>@if ($sched)<span class="chip chip-blue">Scheduled</span>@else<span class="chip">Not set up</span>@endif</td>
                    <td class="text-right">@if ($sched)<a href="{{ $bu }}/recurring/{{ $sched->id }}">Schedule</a>@endif</td></tr>
            @endif
            @foreach ($contract->milestones->sortBy('due_date') as $m)
                <tr><td><div class="font-medium">{{ $m->name }}</div>@if ($m->description)<div class="text-xs text-slate-500">{{ $m->description }}</div>@endif</td>
                    <td>{{ $m->due_date?->format('M j, Y') ?? 'On signing' }}</td><td class="num">@money($m->amount_cents)</td><td>@include('partials.status', ['s' => $m->status])</td>
                    <td class="text-right">@if ($m->invoice_id)<a href="{{ $bu }}/invoices/{{ $m->invoice_id }}">Invoice</a>@elseif ($ctx->can('invoices.manage') && $contract->isActiveForBilling())
                        <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/milestones/{{ $m->id }}/invoice">@csrf<button class="btn btn-sm">Create invoice</button></form>@endif</td></tr>
            @endforeach
            </tbody></table></div>
        @endif
        @if ($contract->invoices->isNotEmpty() || $contract->schedules->isNotEmpty())
        <div class="card card-body text-sm">
            <h2>Billing</h2>
            @foreach ($contract->invoices as $i)<div><a href="{{ $bu }}/invoices/{{ $i->id }}">{{ $i->displayNumber() }}</a> · @money($i->total_cents) · {{ $i->statusLabel() }}</div>@endforeach
            @foreach ($contract->schedules as $s)<div>Recurring: <a href="{{ $bu }}/recurring/{{ $s->id }}">{{ $s->name }}</a> · next {{ $s->next_run_date?->format('M j, Y') ?? '—' }} · {{ $s->status }}</div>@endforeach
        </div>
        @endif
        <div class="card overflow-x-auto"><div class="card-head"><h2>Signature activity</h2></div>
            <table class="tbl"><thead><tr><th>#</th><th>When (UTC)</th><th>Event</th><th>Details</th></tr></thead><tbody>
            @forelse ($events as $e)
                <tr><td>{{ $e->seq }}</td><td class="whitespace-nowrap">{{ $e->created_at->utc()->format('Y-m-d H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td>
                    <td class="text-xs">{{ collect($e->details ?? [])->except(['signature_hash'])->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }} @if($e->ip) · IP {{ $e->ip }}@endif</td></tr>
            @empty <tr><td colspan="4" class="text-slate-500">No activity yet.</td></tr> @endforelse
            </tbody></table></div>
    </div>
    <aside class="space-y-4">
        @if ($current)
        <div class="card"><div class="card-head"><h2>Recipients</h2></div><ul class="card-body space-y-2 text-sm">
            @foreach ($current->recipients as $r)
                <li class="flex items-start justify-between gap-2"><span><span class="font-medium">{{ $r->name }}</span> <span class="text-slate-500">{{ $r->email }}</span><br><span class="text-xs">{{ ['client_signer' => 'Client signer', 'countersigner' => 'Countersigner', 'cc' => 'Copy'][$r->role] }}{{ $contract->signing_order === 'sequential' && $r->role !== 'cc' ? ' · order '.$r->sign_order : '' }}</span>
                    @if ($r->signed_at)<br><span class="text-xs text-emerald-700">Signed {{ $r->signed_at->timezone($biz->timezone)->format('M j, g:ia') }} ({{ $r->signature_kind }})</span>@endif
                    @if ($r->declined_at)<br><span class="text-xs text-red-700">Declined: {{ $r->decline_reason }}</span>@endif</span>
                    <span class="text-right">@include('partials.status', ['s' => $r->status])
                        @if ($r->role === 'client_signer' && $r->status === 'sent' && in_array($current->status, ['sent', 'partially_signed']) && $ctx->can('contracts.send'))
                            <form method="post" action="{{ $bu }}/contract-recipients/{{ $r->id }}/remind" class="mt-1">@csrf<button class="btn btn-sm">Remind</button></form>@endif</span></li>
            @endforeach
        </ul></div>
        <div class="card"><div class="card-head"><h2>Documents</h2></div><ul class="card-body space-y-1 text-sm">
            @foreach ($contract->versions as $v)
                <li>v{{ $v->version }} @include('partials.status', ['s' => $v->status])
                    @if ($v->rendered_document_id) · <a href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $v->version }}/document" target="_blank" rel="noopener">Document</a>@endif
                    @if ($v->signed_document_id && $v->signed_document_id !== $v->rendered_document_id) · <a href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $v->version }}/signed" target="_blank" rel="noopener">Signed</a>@endif
                    @if ($v->certificate_document_id) · <a href="{{ $bu }}/contracts/{{ $contract->id }}/versions/{{ $v->version }}/certificate" target="_blank" rel="noopener">Certificate</a>@endif
                    @if ($v->sha256)<div class="font-mono text-[10px] text-slate-500">SHA-256 {{ $v->sha256 }}</div>@endif
                </li>
            @endforeach
        </ul></div>
        @endif
        @if ($ctx->can('contracts.send') && $current && in_array($current->status, ['draft', 'prepared', 'sent', 'partially_signed']))
            <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/void" class="card card-body space-y-2" data-confirm="Void this agreement version? It can no longer be signed.">@csrf
                <label class="label" for="void-reason">Void {{ in_array($current->status, ['sent', 'partially_signed']) ? 'envelope' : 'agreement' }}</label><input class="input" id="void-reason" name="reason" required placeholder="Reason">
                <button class="btn btn-danger btn-sm">Void</button>
                <p class="hint">Signed agreements can’t be voided — terminate them instead.</p></form>
        @endif
        @if ($ctx->can('contracts.manage'))
            <details class="card"><summary class="card-head cursor-pointer"><h2>Upload externally signed copy</h2></summary>
                <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/external" enctype="multipart/form-data" class="card-body space-y-2">@csrf
                    <input class="input" type="file" name="file" accept="application/pdf" required aria-label="Signed PDF">
                    <textarea class="input" name="provenance" rows="2" required placeholder="How/when it was signed (e.g. wet-signed at kickoff meeting 3/2, scanned by Dana)" aria-label="Provenance"></textarea>
                    <button class="btn btn-sm">Upload</button>
                    <p class="hint">Labeled “externally signed”. No in-app signature evidence is created.</p></form></details>
            @if ($contract->isExecuted() && ! $contract->terminated_at)
            <details class="card"><summary class="card-head cursor-pointer"><h2>Terminate</h2></summary>
                <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/terminate" class="card-body space-y-2" data-confirm="Terminate? Linked recurring billing will be paused.">@csrf
                    <input class="input" type="date" name="date" value="{{ $biz->localToday()->toDateString() }}" aria-label="Termination date"><input class="input" name="reason" required placeholder="Reason" aria-label="Reason">
                    <button class="btn btn-danger btn-sm">Terminate agreement</button></form></details>
            @endif
            @if ($contract->isEnded())
            <details class="card" open><summary class="card-head cursor-pointer"><h2>Reactivate</h2></summary>
                <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/reactivate" class="card-body space-y-2" data-confirm="Reactivate this agreement? Paused contract billing will resume.">@csrf
                    <p class="text-xs text-slate-600">Puts the signed agreement back in force without re-signing (e.g. both parties agreed to extend). Audited. To change terms, use Renew instead.</p>
                    <div><label class="label" for="re-end">New end date</label><input class="input" type="date" id="re-end" name="term_end_date" min="{{ $biz->localToday()->toDateString() }}"><p class="hint">Leave blank for open-ended.</p></div>
                    <input class="input" name="reason" required placeholder="Reason (e.g. extended by email 10/30)" aria-label="Reason">
                    <button class="btn btn-primary btn-sm">Reactivate</button></form></details>
            @endif
            @if (! $contract->isExecuted())
            <details class="card"><summary class="card-head cursor-pointer"><h2>Billing override</h2></summary>
                <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/billing-override" class="card-body space-y-2">@csrf
                    <p class="text-xs text-slate-600">Allows milestone/recurring billing before in-app signatures complete (e.g. agreement executed elsewhere). Audited.</p>
                    <input class="input" name="reason" required value="{{ $contract->billing_override_reason }}" aria-label="Reason"><button class="btn btn-sm">Save override</button></form></details>
            @endif
            <details class="card border-red-200"><summary class="card-head cursor-pointer"><h2 class="text-red-800">Delete agreement</h2></summary>
                @if ($deleteBlocker)
                    <p class="card-body text-sm text-slate-600">Can’t be deleted: {{ $deleteBlocker }}</p>
                @else
                    <form method="post" action="{{ $bu }}/contracts/{{ $contract->id }}/delete" class="card-body space-y-2">@csrf
                        <p class="text-xs text-slate-600">Permanently removes this agreement, every version, the signature trail and stored PDFs. This can’t be undone.@if ($contract->wasExecuted()) <strong>This agreement was signed</strong> — keep a copy of the signed PDF first if you need it.@endif</p>
                        <label class="label" for="del-confirm">Type <span class="font-mono">{{ $contract->number }}</span> to confirm</label>
                        <input class="input" id="del-confirm" name="confirm" required autocomplete="off">
                        <input class="input" name="reason" placeholder="Reason (optional)" aria-label="Reason">
                        <button class="btn btn-danger btn-sm">Delete permanently</button></form>
                @endif
            </details>
        @endif
    </aside>
</div>
@endsection
