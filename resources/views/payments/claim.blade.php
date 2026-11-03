@extends('layouts.app')
@section('title', 'Payment report')
@section('content')
<h1>Client payment report @include('partials.status', ['s' => $claim->status])</h1>
<p class="text-sm text-slate-500">{{ $claim->invoice->client->display_name }} · <a href="{{ $bu }}/invoices/{{ $claim->invoice_id }}">{{ $claim->invoice->number }}</a> · submitted {{ $claim->created_at->timezone($biz->timezone)->format('M j, Y g:ia') }}</p>
<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card card-body">
        <h2>What the client reported</h2>
        <dl class="dl mt-3">
            <dt>Method</dt><dd>{{ ucfirst($claim->method) }}</dd>
            <dt>Amount</dt><dd>@money($claim->reported_amount_cents)</dd>
            <dt>{{ $claim->method === 'check' ? 'Mailed on' : 'Sent on' }}</dt><dd>{{ $claim->reported_date->format('M j, Y') }}</dd>
            @if ($claim->check_number)<dt>Check #</dt><dd>{{ $claim->check_number }}</dd>@endif
            @if ($claim->reference)<dt>Reference</dt><dd>{{ $claim->reference }}</dd>@endif
            @if ($claim->note)<dt>Note</dt><dd>{{ $claim->note }}</dd>@endif
            @if ($claim->attachment_document_id)<dt>Receipt</dt><dd><a href="{{ $bu }}/claims/{{ $claim->id }}/attachment">Download attachment</a></dd>@endif
            <dt>Invoice balance</dt><dd>@money($claim->invoice->balance_cents)</dd>
        </dl>
        <p class="alert alert-info mt-4">A report is not proof of payment. Check your bank for the Zelle deposit, or the physical check, before confirming.</p>
    </div>
    @if ($claim->isOpen() && $ctx->can('payments.confirm'))
    <div class="space-y-4">
        <form method="post" action="{{ $bu }}/claims/{{ $claim->id }}/confirm" class="card card-body space-y-3">@csrf
            <h2>Confirm received payment</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="amount">Amount actually received ($)</label><input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ number_format($claim->reported_amount_cents / 100, 2, '.', '') }}" required></div>
                <div><label class="label" for="received_date">Date received</label><input class="input" id="received_date" name="received_date" type="date" value="{{ $biz->localToday()->toDateString() }}" required><p class="hint">Policy: payment date = date you received the funds or check.</p></div>
                <div><label class="label" for="deposit_account_id">Deposit to</label><select class="input" id="deposit_account_id" name="deposit_account_id">@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($a->id === $defaultAccount->id)>{{ $a->label() }}</option>@endforeach</select></div>
                <div><label class="label" for="reference">Bank reference</label><input class="input" id="reference" name="reference" value="{{ $claim->reference }}"></div>
            </div>
            <label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="evidence_checked" value="1" required> I verified this payment in our bank account or have the physical check.</label>
            <label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="send_receipt" value="1" checked> Email the client a thank-you with the paid invoice</label>
            <button class="btn btn-primary">Confirm &amp; record payment</button>
        </form>
        <form method="post" action="{{ $bu }}/claims/{{ $claim->id }}/clarify" class="card card-body space-y-2">@csrf
            <label class="label" for="clarify-note">Need more information?</label><input class="input" id="clarify-note" name="note" placeholder="What's missing" required>
            <button class="btn">Mark as needing clarification</button>
        </form>
        <form method="post" action="{{ $bu }}/claims/{{ $claim->id }}/reject" class="card card-body space-y-2" data-confirm="Reject this payment report?">@csrf
            <label class="label" for="reject-note">Reject</label><input class="input" id="reject-note" name="note" placeholder="Reason (e.g. no matching deposit)" required>
            <button class="btn btn-danger">Reject report</button>
        </form>
    </div>
    @else
    <div class="card card-body text-sm">
        <h2>Review</h2>
        <dl class="dl mt-3">
            <dt>Reviewed by</dt><dd>{{ $claim->reviewer?->label() ?? '—' }}</dd>
            <dt>When</dt><dd>{{ $claim->reviewed_at?->timezone($biz->timezone)->format('M j, Y g:ia') ?? '—' }}</dd>
            <dt>Note</dt><dd>{{ $claim->review_note ?? '—' }}</dd>
            @if ($claim->payment)<dt>Payment</dt><dd><a href="{{ $bu }}/payments/{{ $claim->payment_id }}">View recorded payment</a></dd>@endif
        </dl>
    </div>
    @endif
</div>
@endsection
