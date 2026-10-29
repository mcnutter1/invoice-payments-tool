@extends('layouts.app')
@section('title', 'Invoice '.$invoice->displayNumber())
@section('content')
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>Invoice {{ $invoice->displayNumber() }} @include('partials.invoice-status', ['i' => $invoice])</h1>
        <p class="text-sm text-slate-500"><a href="{{ $bu }}/clients/{{ $invoice->client_id }}">{{ $invoice->client->display_name }}</a>
            @if ($invoice->issue_date) · Issued {{ $invoice->issue_date->format('M j, Y') }} @endif
            @if ($invoice->due_date) · Due {{ $invoice->due_date->format('M j, Y') }} @endif
            @if ($invoice->replaces_invoice_id) · Replaces <a href="{{ $bu }}/invoices/{{ $invoice->replaces_invoice_id }}">voided invoice</a> @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($invoice->isDraft())
            @if ($ctx->can('invoices.manage'))<a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}/edit">Edit</a>@endif
            <a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}/pdf" target="_blank" rel="noopener">Preview PDF</a>
        @else
            <a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}/pdf">Download PDF</a>
        @endif
        @if ($invoice->isIssued() && $ctx->can('invoices.send'))<a class="btn btn-primary" href="{{ $bu }}/invoices/{{ $invoice->id }}/send">{{ $outbox->isEmpty() ? 'Send' : 'Send again / remind' }}</a>@endif
        @if ($invoice->isIssued() && $invoice->balance_cents > 0 && $ctx->can('payments.record'))<a class="btn" href="{{ $bu }}/payments/new?invoice={{ $invoice->id }}">Record payment</a>@endif
        @if ($ctx->can('invoices.manage'))<form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/duplicate">@csrf<button class="btn btn-ghost">Duplicate</button></form>@endif
    </div>
</div>

@if ($invoice->isDraft())
    <div class="card card-body mt-4 border-brand-200 bg-brand-50">
        <h2>Review &amp; issue</h2>
        <p class="mt-1 text-sm text-slate-700">Issuing assigns the next invoice number, freezes the client, branding and payment-instruction snapshot, generates the PDF and posts <strong>@money($invoice->total_cents)</strong> to Accounts Receivable. It does not email the client — you can send it next.</p>
        @if ($invoice->lines->isEmpty())<p class="alert alert-warn mt-2">Add at least one line before issuing.</p>@endif
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($ctx->can('invoices.issue') && $invoice->lines->isNotEmpty())
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/issue" data-confirm="Issue this invoice for {{ \App\Support\Money::format($invoice->total_cents) }}? It will be numbered and posted to the ledger.">@csrf<button class="btn btn-primary">Issue invoice</button></form>
            @endif
            @if ($ctx->can('invoices.manage'))
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/delete" data-confirm="Delete this draft?">@csrf<button class="btn btn-danger">Delete draft</button></form>
            @endif
        </div>
    </div>
@endif

@if ($invoice->status === 'void')
    <div class="alert alert-warn mt-4">Voided {{ $invoice->voided_at?->timezone($biz->timezone)->format('M j, Y') }}: {{ $invoice->void_reason }}. The issue entry was reversed; the number is never reused.</div>
@endif

<div class="mt-6 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        <div class="card overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">Discount</th><th class="num">Tax</th></tr></thead>
                <tbody>
                @foreach ($invoice->lines as $l)
                    <tr><td><div class="font-medium">{{ $l->name }}</div>@if($l->description)<div class="whitespace-pre-line text-xs text-slate-500">{{ $l->description }}</div>@endif</td>
                        <td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}</td><td class="num">${{ number_format((float) $l->unit_price, 2) }}</td>
                        <td class="num">@money($l->amount_cents)</td><td class="num">{{ $l->discount_cents ? '−'.\App\Support\Money::format($l->discount_cents) : '' }}</td><td class="num">{{ $l->tax_cents ? \App\Support\Money::format($l->tax_cents) : '' }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="text-sm">
                    <tr><td colspan="5" class="num px-3 py-1">Subtotal</td><td class="num px-3 py-1">@money($invoice->subtotal_cents)</td></tr>
                    @if ($invoice->discount_cents)<tr><td colspan="5" class="num px-3 py-1">Discount</td><td class="num px-3 py-1">−@money($invoice->discount_cents)</td></tr>@endif
                    @foreach ($invoice->taxLines as $t)<tr><td colspan="5" class="num px-3 py-1">{{ $t->name }} on @money($t->taxable_cents)</td><td class="num px-3 py-1">@money($t->tax_cents)</td></tr>@endforeach
                    <tr><td colspan="5" class="num px-3 py-1 font-semibold">Total</td><td class="num px-3 py-1 font-semibold">@money($invoice->total_cents)</td></tr>
                    @if ($invoice->isIssued())
                        <tr><td colspan="5" class="num px-3 py-1">Paid</td><td class="num px-3 py-1">−@money($invoice->paid_cents)</td></tr>
                        @if ($invoice->credited_cents)<tr><td colspan="5" class="num px-3 py-1">Credits</td><td class="num px-3 py-1">−@money($invoice->credited_cents)</td></tr>@endif
                        @if ($invoice->written_off_cents)<tr><td colspan="5" class="num px-3 py-1">Written off</td><td class="num px-3 py-1">−@money($invoice->written_off_cents)</td></tr>@endif
                        <tr><td colspan="5" class="num px-3 py-1 font-semibold">Balance due</td><td class="num px-3 py-1 font-semibold">@money($invoice->balance_cents)</td></tr>
                    @endif
                </tfoot>
            </table>
        </div>

        @if ($invoice->allocations->isNotEmpty())
        <div class="card overflow-x-auto"><div class="card-head"><h2>Payments &amp; credits</h2></div>
            <table class="tbl"><thead><tr><th>Date</th><th>Source</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
            @foreach ($invoice->allocations as $a)
                <tr><td>{{ $a->applied_date->format('M j, Y') }}</td>
                    <td>@if($a->payment)<a href="{{ $bu }}/payments/{{ $a->payment_id }}">{{ ucfirst($a->payment->method) }} payment</a>@else {{ $a->creditNote?->kind === 'write_off' ? 'Write-off' : 'Credit note' }} {{ $a->creditNote?->number }} @endif</td>
                    <td class="num">@money($a->amount_cents)</td><td>@include('partials.status', ['s' => $a->status === 'active' ? 'posted' : 'reversed'])</td>
                    <td class="text-right">@if($a->payment_id && $a->status === 'active' && $ctx->can('payments.reverse'))
                        <form method="post" action="{{ $bu }}/allocations/{{ $a->id }}/unapply" class="inline-flex gap-1" data-confirm="Move this amount back to the client's unapplied credit?">@csrf<input class="input input-sm w-36" name="reason" placeholder="Reason" required aria-label="Reason"><button class="btn btn-sm">Unapply</button></form>
                    @endif</td></tr>
            @endforeach
            </tbody></table></div>
        @endif

        @if ($invoice->claims->isNotEmpty())
        <div class="card"><div class="card-head"><h2>Client payment reports</h2></div><div class="card-body space-y-2 text-sm">
            @foreach ($invoice->claims as $c)
                <div class="flex justify-between"><a href="{{ $bu }}/claims/{{ $c->id }}">{{ ucfirst($c->method) }} · @money($c->reported_amount_cents) · {{ $c->reported_date->format('M j') }}</a>@include('partials.status', ['s' => $c->status])</div>
            @endforeach
        </div></div>
        @endif

        @if ($invoice->isIssued())
        <div class="card"><div class="card-head"><h2>Delivery &amp; tracking</h2>
            @if ($links->whereNull('revoked_at')->isNotEmpty() && $ctx->can('invoices.send'))
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/revoke-links" data-confirm="Revoke all client links for this invoice?">@csrf<button class="btn btn-sm btn-ghost">Revoke links</button></form>
            @endif
        </div>
            <div class="card-body space-y-3 text-sm">
                @forelse ($outbox as $o)
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ ucfirst($o->kind) }} to {{ $o->to_email }} · {{ $o->created_at->timezone($biz->timezone)->format('M j, g:ia') }}</span>
                        <span>@include('partials.status', ['s' => $o->status])
                            @if ($o->needs_review && $ctx->can('invoices.send') && $o->encrypted_body)
                                <form method="post" action="{{ $bu }}/outbox/{{ $o->id }}/resend" class="inline" data-confirm="{{ $o->status === 'delivery_unknown' ? 'The mail service may already have accepted this message. Resend anyway?' : 'Re-queue this message?' }}">@csrf<button class="btn btn-sm">Resend after review</button></form>
                            @endif
                        </span>
                    </div>
                    @if ($o->last_error)<p class="text-xs text-red-700">{{ $o->last_error }}</p>@endif
                @empty
                    <p class="text-slate-500">Not emailed yet.</p>
                @endforelse
                <p class="hint">“Accepted by mail service” means the notification service took the message; it is not proof of delivery. Delivery confirmations are not available from the current mail integration.</p>
                @if ($access->isNotEmpty())
                    <div class="border-t border-slate-100 pt-2">
                        <div class="font-medium">Client activity</div>
                        <ul class="mt-1 space-y-0.5 text-xs">
                            @foreach ($access as $e)
                                <li>{{ $e->created_at->timezone($biz->timezone)->format('M j, g:ia') }} · {{ ['link_accessed' => 'Link opened', 'invoice_viewed' => 'Invoice viewed', 'pdf_downloaded' => 'PDF downloaded', 'payment_reported' => 'Payment reported', 'contract_viewed' => 'Agreement opened'][$e->event] ?? $e->event }}
                                    @if ($e->probable_automation)<span class="chip chip-gray">possibly automated (link scanner)</span>@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if ($invoice->isIssued() && $ctx->can('invoices.void'))
        <div class="card"><div class="card-head"><h2>Corrections</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3 text-sm">
                <div><a class="btn w-full" href="{{ $bu }}/invoices/{{ $invoice->id }}/credit">Issue credit note</a><p class="hint">Reduce the amount owed (e.g. price adjustment).</p></div>
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/write-off" data-confirm="Write off this amount as bad debt?">@csrf
                    <label class="label" for="wo">Write off</label><input class="input input-sm" id="wo" name="amount" type="number" step="0.01" min="0.01" max="{{ $invoice->balance_cents / 100 }}" value="{{ number_format($invoice->balance_cents / 100, 2, '.', '') }}">
                    <input class="input input-sm mt-1" name="reason" placeholder="Reason" required aria-label="Write-off reason"><button class="btn btn-sm mt-1">Write off</button></form>
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/void" data-confirm="Void this invoice? The ledger entry is reversed and the number is retired.">@csrf
                    <label class="label" for="vr">Void</label><input class="input input-sm" id="vr" name="reason" placeholder="Reason" required>
                    <label class="mt-1 flex items-center gap-2"><input class="check" type="checkbox" name="reissue" value="1" checked> Create replacement draft</label>
                    <button class="btn btn-sm btn-danger mt-1">Void invoice</button>
                    @if ($invoice->allocations->where('status', 'active')->isNotEmpty())<p class="hint">Unapply payments/credits first.</p>@endif
                </form>
            </div>
        </div>
        @endif
    </div>

    <aside class="space-y-4">
        <div class="card card-body text-sm">
            <dl class="dl">
                <dt>Total</dt><dd>@money($invoice->total_cents)</dd>
                <dt>Balance</dt><dd class="font-semibold">@money($invoice->balance_cents)</dd>
                <dt>Methods</dt><dd>{{ collect($invoice->payment_methods ?? [])->map(fn ($m) => $m === 'zelle' ? 'Zelle' : 'Check')->implode(', ') ?: '—' }}</dd>
                @if ($invoice->po_number)<dt>PO</dt><dd>{{ $invoice->po_number }}</dd>@endif
                @if ($invoice->service_start)<dt>Service</dt><dd>{{ $invoice->service_start->format('M j') }} – {{ $invoice->service_end?->format('M j, Y') }}</dd>@endif
                @if ($invoice->contractVersion)<dt>Agreement</dt><dd><a href="{{ $bu }}/contracts/{{ $invoice->contract_id }}">{{ $invoice->contractVersion->contract->number }} v{{ $invoice->contractVersion->version }}</a></dd>@endif
                @if ($invoice->recurring_schedule_id)<dt>Recurring</dt><dd><a href="{{ $bu }}/recurring/{{ $invoice->recurring_schedule_id }}">Schedule</a></dd>@endif
                @if ($invoice->issue_journal_entry_id && $ctx->can('journals.view'))<dt>Ledger</dt><dd><a href="{{ $bu }}/journals/{{ $invoice->issue_journal_entry_id }}">Entry #{{ $invoice->issue_journal_entry_id }}</a></dd>@endif
            </dl>
            @if ($invoice->internal_notes)<p class="mt-3 rounded bg-amber-50 p-2 text-xs text-amber-900"><strong>Internal:</strong> {{ $invoice->internal_notes }}</p>@endif
        </div>
        @if ($invoice->documentVersions->isNotEmpty())
        <div class="card"><div class="card-head"><h2>PDF versions</h2></div><ul class="card-body space-y-1 text-sm">
            @foreach ($invoice->documentVersions as $v)<li><a href="{{ $bu }}/invoices/{{ $invoice->id }}/documents/{{ $v->version }}">Version {{ $v->version }}</a> · {{ $v->reason }} · <span class="font-mono text-xs text-slate-500" title="SHA-256">{{ substr($v->sha256, 0, 12) }}…</span></li>@endforeach
        </ul></div>
        @endif
        <div class="card"><div class="card-head"><h2>Activity</h2></div><ul class="card-body space-y-1 text-xs">
            @forelse ($activity as $a)<li>{{ $a->created_at->timezone($biz->timezone)->format('M j, g:ia') }} · {{ $a->action }} · {{ $a->actor?->label() ?? ucfirst($a->actor_type) }}@if($a->reason) — {{ $a->reason }}@endif</li>@empty<li class="text-slate-500">No activity.</li>@endforelse
        </ul></div>
    </aside>
</div>
@endsection
