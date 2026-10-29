@extends('layouts.app')
@section('title', 'Credit note for '.$invoice->number)
@section('content')
<h1>Credit note for {{ $invoice->number }}</h1>
<p class="mt-1 text-sm text-slate-500">Open balance: <strong>@money($invoice->balance_cents)</strong>. A credit note reduces revenue (and tax, if you enter a rate) and the amount the client owes. To return money already paid, refund it from the payment instead.</p>
<form method="post" action="{{ $bu }}/invoices/{{ $invoice->id }}/credit" class="card card-body mt-4 max-w-3xl space-y-4">
    @csrf
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="reason">Reason</label><input class="input" id="reason" name="reason" required value="{{ old('reason') }}"></div>
        <div><label class="label" for="issue_date">Date</label><input class="input" id="issue_date" name="issue_date" type="date" value="{{ old('issue_date', $biz->localToday()->toDateString()) }}"></div>
    </div>
    <table class="tbl"><thead><tr><th>Description</th><th>Income account</th><th class="w-32">Amount ($, before tax)</th><th class="w-24">Tax %</th></tr></thead><tbody>
        @foreach ($invoice->lines->take(10) as $i => $l)
            <tr>
                <td><input class="input input-sm" name="lines[{{ $i }}][description]" value="Credit: {{ $l->name }}" aria-label="Description"></td>
                <td><select class="input input-sm" name="lines[{{ $i }}][income_account_id]" aria-label="Income account">@foreach ($incomeAccounts as $a)<option value="{{ $a->id }}" @selected($a->id === $l->income_account_id)>{{ $a->label() }}</option>@endforeach</select></td>
                <td><input class="input input-sm" name="lines[{{ $i }}][amount]" type="number" step="0.01" min="0" placeholder="0.00" aria-label="Amount"></td>
                <td><input class="input input-sm" name="lines[{{ $i }}][tax_rate]" type="number" step="0.0001" min="0" value="{{ (float) $l->tax_rate }}" aria-label="Tax rate"></td>
            </tr>
        @endforeach
    </tbody></table>
    <div class="flex gap-2"><button class="btn btn-primary" data-confirm="Issue this credit note? It posts to the ledger immediately.">Issue credit note</button><a class="btn" href="{{ $bu }}/invoices/{{ $invoice->id }}">Cancel</a></div>
</form>
@endsection
