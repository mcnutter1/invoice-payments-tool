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
@if ($invoice->contractVersion)
    <p class="mt-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">Invoice related to agreement:
        <a class="font-medium" href="{{ $bu }}/contracts/{{ $invoice->contract_id ?? $invoice->contractVersion->contract_id }}">{{ $invoice->contractVersion->title }}</a>
        <span class="text-slate-500">· {{ $invoice->contractVersion->contract?->number }} v{{ $invoice->contractVersion->version }}</span></p>
@endif
@unless ($invoice->isDraft())
<div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="metric"><div class="metric-label">Total</div><div class="metric-value">@money($invoice->total_cents)</div></div>
    <div class="metric"><div class="metric-label">Received</div><div class="metric-value">@money($invoice->paid_cents)</div>@if($invoice->credited_cents + $invoice->written_off_cents)<div class="metric-note">+ @money($invoice->credited_cents + $invoice->written_off_cents) credits</div>@endif</div>
    <div class="metric"><div class="metric-label">Balance due</div><div @class(['metric-value', 'text-red-700' => $invoice->isOverdue()])>@money($invoice->status === 'void' ? 0 : $invoice->balance_cents)</div></div>
    <div class="metric"><div class="metric-label">Due</div><div class="metric-value text-lg">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</div><div class="metric-note">{{ $invoice->statusLabel() }}</div></div>
</div>
@endunless

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
    <div class="alert alert-warn mt-4"><strong>Cancelled</strong> {{ $invoice->voided_at?->timezone($biz->timezone)->format('M j, Y') }}: {{ $invoice->void_reason }}. The ledger entry was reversed, client links were revoked and the number is never reused.</div>
@endif

<div @class(['mt-6 grid gap-4 xl:grid-cols-3', 'opacity-70' => $invoice->status === 'void'])>
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
                        <span class="flex flex-wrap items-center gap-1">@include('partials.status', ['s' => $o->status])
                            @if ($o->opened_at)<span class="chip chip-blue" title="First opened {{ $o->opened_at->timezone($biz->timezone)->format('M j, g:ia') }}">Opened{{ $o->open_count > 1 ? ' ×'.$o->open_count : '' }}</span>@endif
                            @if ($o->clicked_at)<span class="chip chip-green" title="First click {{ $o->clicked_at->timezone($biz->timezone)->format('M j, g:ia') }}">Clicked{{ $o->click_count > 1 ? ' ×'.$o->click_count : '' }}</span>@endif
                            @if ($o->needs_review && $ctx->can('invoices.send') && $o->encrypted_body)
                                <form method="post" action="{{ $bu }}/outbox/{{ $o->id }}/resend" class="inline" data-confirm="{{ $o->status === 'delivery_unknown' ? 'The mail service may already have accepted this message. Resend anyway?' : 'Re-queue this message?' }}">@csrf<button class="btn btn-sm">Resend after review</button></form>
                            @endif
                        </span>
                    </div>
                    @if ($o->last_error)<p class="text-xs text-red-700">{{ $o->last_error }}</p>@endif
                @empty
                    <p class="text-slate-500">Not emailed yet.</p>
                @endforelse
                <p class="hint">“Accepted by mail service” means the notification service took the message; it is not proof of delivery. “Opened” relies on images loading and is approximate (some mail apps preload images, others block them). Full client activity is in the Activity panel.</p>
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
                <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/void" data-confirm="Cancel this invoice? The ledger entry is reversed, client links stop working and the number is retired.">@csrf
                    <label class="label" for="vr">Cancel invoice</label><input class="input input-sm" id="vr" name="reason" placeholder="Reason" required>
                    <label class="mt-1 flex items-center gap-2"><input class="check" type="checkbox" name="reissue" value="1" checked> Create replacement draft</label>
                    <button class="btn btn-sm btn-danger mt-1">Cancel invoice</button>
                    @if ($invoice->allocations->where('status', 'active')->isNotEmpty())<p class="hint">Unapply payments/credits first.</p>@endif
                </form>
            </div>
        </div>
        @endif

        @if (! $invoice->isDraft() && $ctx->can('invoices.void'))
        <div class="card border-red-200"><div class="card-head"><h2>Delete permanently</h2></div>
            <div class="card-body text-sm">
                @if ($purgeBlocker)
                    <p class="text-slate-600">This invoice can’t be deleted: {{ $purgeBlocker }}</p>
                @else
                    <p class="text-slate-600">Removes the invoice, its ledger entries, PDFs, client links, emails and tracking as if it never existed. The number is not reused. Only a record in the audit log remains. This cannot be undone.</p>
                    <form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/purge" class="mt-3 grid gap-2 sm:grid-cols-3" data-confirm="Permanently delete invoice {{ $invoice->number }}? This cannot be undone.">@csrf
                        <input class="input input-sm" name="reason" placeholder="Reason" required aria-label="Reason for deletion">
                        <input class="input input-sm" name="confirm" placeholder="Type {{ $invoice->number }}" required aria-label="Type the invoice number to confirm" autocomplete="off">
                        <button class="btn btn-sm btn-danger">Delete permanently</button>
                    </form>
                @endif
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
        <div class="card"><div class="card-head"><h2>Activity</h2></div>
            <ol class="card-body max-h-[36rem] space-y-2 overflow-y-auto text-xs">
            @forelse ($timeline as $t)
                <li class="flex gap-2">
                    <span @class(['mt-1 h-2 w-2 flex-none rounded-full', 'bg-slate-400' => $t['kind'] === 'staff', 'bg-brand-600' => $t['kind'] === 'email', 'bg-emerald-500' => $t['kind'] === 'client' && empty($t['automated']), 'bg-slate-300' => $t['kind'] === 'client' && ! empty($t['automated'])]) aria-hidden="true"></span>
                    <span @class(['text-slate-400' => ! empty($t['automated'])])>
                        <span class="font-medium text-slate-800">{{ $t['label'] }}</span>
                        @if (! empty($t['automated']))<span class="chip chip-gray">possibly automated</span>@endif
                        <br><span class="text-slate-500">{{ $t['at']->timezone($biz->timezone)->format('M j, g:ia') }} · {{ $t['who'] }}</span>
                        @if ($t['detail'])<br><span class="text-slate-500">{{ $t['detail'] }}</span>@endif
                    </span>
                </li>
            @empty
                <li class="text-slate-500">No activity.</li>
            @endforelse
            </ol>
        </div>
    </aside>
</div>
@endsection
