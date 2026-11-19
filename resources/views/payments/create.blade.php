@extends('layouts.app')
@section('title', 'Receive payment')
@section('content')
<h1>Receive payment</h1>
<form method="get" class="mt-4 flex max-w-xl items-end gap-2">
    <div class="flex-1"><label class="label" for="client">Client</label>
        <select class="input" id="client" name="client" data-autosubmit>
            <option value="">Choose a client…</option>
            @foreach ($clients as $c)<option value="{{ $c->id }}" @selected($clientId == $c->id)>{{ $c->display_name }}</option>@endforeach
        </select></div>
    <noscript><button class="btn">Load</button></noscript>
</form>
@if ($clientId)
<form method="post" action="{{ $bu }}/payments" class="mt-4 grid max-w-4xl gap-4" data-payment-form data-fee-form>
    @csrf
    <input type="hidden" name="client_id" value="{{ $clientId }}">
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="method">Method</label><select class="input" id="method" name="method" data-method>@foreach (\App\Services\Payments\PaymentService::METHODS as $k => $v)<option value="{{ $k }}" @selected(old('method') === $k)>{{ $v }}</option>@endforeach</select>
            <p class="hint">Card payments made on the invoice page are recorded automatically.</p></div>
        <div><label class="label" for="amount">Amount received ($)</label><input class="input" id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" data-amount data-gross><p class="hint">Gross amount the client paid — applied to invoices.</p></div>
        <div><label class="label" for="received_date">Date received</label><input class="input" id="received_date" name="received_date" type="date" required value="{{ old('received_date', $biz->localToday()->toDateString()) }}"></div>
        <div><label class="label" for="fee">Processing / bank fee ($)</label><input class="input" id="fee" name="fee" type="number" step="0.01" min="0" value="{{ old('fee') }}" placeholder="0.00" data-fee>
            <p class="hint">E.g. incoming-wire or Venmo fee. Expensed to Bank &amp; Merchant Fees; net deposit <strong data-net-out>$0.00</strong>.</p></div>
        <div><label class="label" for="deposit_account_id">Deposit to</label>
            <select class="input" id="deposit_account_id" name="deposit_account_id">
                <option value="">Automatic (checks/cash → Undeposited Funds, others → bank)</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->label() }}</option>@endforeach
            </select></div>
        <div><label class="label" for="check_number">Check #</label><input class="input" id="check_number" name="check_number" value="{{ old('check_number') }}"></div>
        <div><label class="label" for="reference">Reference / confirmation #</label><input class="input" id="reference" name="reference" value="{{ old('reference') }}" placeholder="Zelle, ACH trace, wire IMAD or Venmo ID"></div>
        <div class="sm:col-span-2"><label class="label" for="memo">Memo</label><input class="input" id="memo" name="memo" value="{{ old('memo') }}"></div>
    </div>
    <div class="card overflow-x-auto">
        <div class="card-head"><h2>Apply to invoices</h2><button type="button" class="btn btn-sm" data-auto-apply>Apply oldest first</button></div>
        <table class="tbl"><thead><tr><th>Invoice</th><th>Due</th><th class="num">Open balance</th><th class="w-40">Apply ($)</th></tr></thead><tbody>
        @forelse ($openInvoices as $i)
            <tr><td>{{ $i->number }}</td><td>{{ $i->due_date?->format('M j, Y') }}</td><td class="num">@money($i->balance_cents)</td>
                <td><input class="input input-sm" type="number" step="0.01" min="0" max="{{ $i->balance_cents / 100 }}" name="alloc[{{ $i->id }}]" data-alloc data-balance="{{ $i->balance_cents }}"
                    value="{{ old('alloc.'.$i->id, $invoiceId === $i->id ? number_format($i->balance_cents / 100, 2, '.', '') : '') }}" aria-label="Apply to {{ $i->number }}"></td></tr>
        @empty
            <tr><td colspan="4" class="text-slate-500">No open invoices. The full amount will be held as customer credit.</td></tr>
        @endforelse
        </tbody></table>
        <p class="card-body pt-2 text-sm text-slate-600">Unapplied amount: <strong data-unapplied>$0.00</strong> — held as customer credit (not income) until applied or refunded.</p>
    </div>
    <label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="send_receipt" value="1" checked> Email the client a thank-you with the paid invoice</label>
    <div class="flex gap-2"><button class="btn btn-primary">Record payment</button><a href="{{ $bu }}/payments" class="btn">Cancel</a></div>
</form>
@endif
@endsection
