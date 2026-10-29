@extends('layouts.app')
@section('title', 'Payment')
@section('content')
@php $unapplied = $payment->unappliedCents(); $methods = \App\Services\Payments\PaymentService::METHODS; @endphp
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $methods[$payment->method] ?? $payment->method }} payment · @money($payment->amount_cents) @include('partials.status', ['s' => $payment->status])</h1>
        <p class="text-sm text-slate-500"><a href="{{ $bu }}/clients/{{ $payment->client_id }}">{{ $payment->client->display_name }}</a> · received {{ $payment->received_date->format('M j, Y') }}
            @if ($payment->recorder) · recorded by {{ $payment->recorder->label() }} @endif</p>
    </div>
    @if ($payment->journal_entry_id && $ctx->can('journals.view'))<a class="btn" href="{{ $bu }}/journals/{{ $payment->journal_entry_id }}">Ledger entry</a>@endif
</div>
@if ($payment->status === 'reversed')
    <div class="alert alert-err mt-4">Reversed ({{ $payment->reversal_kind }}) on {{ $payment->reversed_at->timezone($biz->timezone)->format('M j, Y') }}: {{ $payment->reversal_reason }}</div>
@endif
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="card overflow-x-auto"><div class="card-head"><h2>Applied to</h2></div>
            <table class="tbl"><thead><tr><th>Invoice</th><th>Date</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse ($payment->allocations as $a)
                <tr><td><a href="{{ $bu }}/invoices/{{ $a->invoice_id }}">{{ $a->invoice->number }}</a></td><td>{{ $a->applied_date->format('M j, Y') }}</td><td class="num">@money($a->amount_cents)</td>
                    <td>@include('partials.status', ['s' => $a->status === 'active' ? 'posted' : 'reversed'])</td>
                    <td class="text-right">@if ($a->status === 'active' && $ctx->can('payments.reverse') && $payment->status !== 'reversed')
                        <form method="post" action="{{ $bu }}/allocations/{{ $a->id }}/unapply" class="inline-flex gap-1" data-confirm="Move this amount back to unapplied credit?">@csrf<input class="input input-sm w-32" name="reason" placeholder="Reason" required aria-label="Reason"><button class="btn btn-sm">Unapply</button></form>
                    @endif</td></tr>
            @empty <tr><td colspan="5" class="text-slate-500">Not applied to any invoice.</td></tr> @endforelse
            </tbody></table>
            <p class="card-body pt-2 text-sm">Unapplied customer credit: <strong>@money($unapplied)</strong></p>
        </div>
        @if ($unapplied > 0 && $payment->status !== 'reversed' && $ctx->can('payments.record') && $openInvoices->isNotEmpty())
        <form method="post" action="{{ $bu }}/payments/{{ $payment->id }}/apply" class="card card-body flex flex-wrap items-end gap-2">@csrf
            <div><label class="label" for="ap-inv">Apply credit to</label><select class="input" id="ap-inv" name="invoice_id">@foreach ($openInvoices as $i)<option value="{{ $i->id }}">{{ $i->number }} (open {{ \App\Support\Money::format($i->balance_cents) }})</option>@endforeach</select></div>
            <div><label class="label" for="ap-amt">Amount</label><input class="input" id="ap-amt" name="amount" type="number" step="0.01" min="0.01" max="{{ $unapplied / 100 }}" value="{{ number_format($unapplied / 100, 2, '.', '') }}"></div>
            <button class="btn btn-primary">Apply</button>
        </form>
        @endif
        @if ($payment->refunds->isNotEmpty())
        <div class="card card-body text-sm"><h2>Refunds</h2>
            @foreach ($payment->refunds as $r)<div>{{ $r->refund_date->format('M j, Y') }} · @money($r->amount_cents) {{ $r->reference }}</div>@endforeach
        </div>
        @endif
    </div>
    <aside class="space-y-4">
        <div class="card card-body text-sm"><dl class="dl">
            <dt>Deposit account</dt><dd>{{ $payment->depositAccount->label() }}</dd>
            @if ($payment->check_number)<dt>Check #</dt><dd>{{ $payment->check_number }}</dd>@endif
            @if ($payment->reference)<dt>Reference</dt><dd>{{ $payment->reference }}</dd>@endif
            @if ($payment->deposited_date)<dt>Deposited</dt><dd>{{ $payment->deposited_date->format('M j, Y') }}</dd>@endif
            @if ($payment->cleared_date)<dt>Cleared</dt><dd>{{ $payment->cleared_date->format('M j, Y') }}</dd>@endif
            @if ($payment->claim)<dt>Client report</dt><dd><a href="{{ $bu }}/claims/{{ $payment->claim_id }}">#{{ $payment->claim_id }}</a></dd>@endif
            <dt>Receipt email</dt><dd>{{ $payment->receipt_emailed_at ? 'Queued '.$payment->receipt_emailed_at->timezone($biz->timezone)->format('M j') : 'Not sent' }}</dd>
            @if ($payment->memo)<dt>Memo</dt><dd>{{ $payment->memo }}</dd>@endif
        </dl></div>
        @if ($payment->status !== 'reversed')
            @if ($payment->status === 'deposited' && $ctx->can('payments.record'))
                <form method="post" action="{{ $bu }}/payments/{{ $payment->id }}/clear" class="card card-body flex items-end gap-2">@csrf
                    <div><label class="label" for="cl-date">Cleared on</label><input class="input" id="cl-date" type="date" name="date" value="{{ $biz->localToday()->toDateString() }}"></div><button class="btn">Mark cleared</button></form>
            @endif
            @if ($ctx->can('payments.reverse'))
                @if ($unapplied > 0)
                <form method="post" action="{{ $bu }}/payments/{{ $payment->id }}/refund" class="card card-body space-y-2" data-confirm="Record a refund of unapplied credit?">@csrf
                    <h3>Refund unapplied credit</h3>
                    <input class="input" name="amount" type="number" step="0.01" min="0.01" max="{{ $unapplied / 100 }}" value="{{ number_format($unapplied / 100, 2, '.', '') }}" aria-label="Refund amount">
                    <select class="input" name="account_id" aria-label="Paid from">@foreach ($banks as $bk)<option value="{{ $bk->id }}">{{ $bk->name }}</option>@endforeach</select>
                    <input class="input" type="date" name="date" value="{{ $biz->localToday()->toDateString() }}" aria-label="Refund date">
                    <input class="input" name="reference" placeholder="Reference" aria-label="Reference">
                    <button class="btn">Record refund</button>
                </form>
                @endif
                <form method="post" action="{{ $bu }}/payments/{{ $payment->id }}/reverse" class="card card-body space-y-2" data-confirm="Reverse this payment? All allocations are reversed and invoices reopen.">@csrf
                    <h3>Reverse payment</h3>
                    <select class="input" name="kind" aria-label="Reason type"><option value="returned">Returned / bounced check</option><option value="error">Recorded in error</option></select>
                    <input class="input" name="reason" placeholder="Explanation" required aria-label="Explanation">
                    <button class="btn btn-danger">Reverse payment</button>
                </form>
            @endif
        @endif
        @if ($activity->isNotEmpty())
        <div class="card"><div class="card-head"><h2>Activity</h2></div><ul class="card-body space-y-1 text-xs">
            @foreach ($activity as $a)<li>{{ $a->created_at->timezone($biz->timezone)->format('M j, g:ia') }} · {{ $a->action }} · {{ $a->actor?->label() ?? $a->actor_type }}</li>@endforeach
        </ul></div>
        @endif
    </aside>
</div>
@endsection
