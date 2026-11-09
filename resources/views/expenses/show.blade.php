@extends('layouts.app')
@section('title', ($expense->kind === 'bill' ? 'Bill' : 'Expense'))
@section('content')
@include('expenses._tabs', ['active' => $expense->source === 'mileage' ? 'mileage' : 'expenses'])
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $expense->kind === 'bill' ? 'Bill' : 'Expense' }} · @money($expense->total_cents) @include('partials.status', ['s' => $expense->status])</h1>
        <p class="text-sm text-slate-500">{{ $expense->vendor?->name ?? 'No vendor' }} · {{ $expense->expense_date->format('M j, Y') }} @if($expense->reference) · {{ $expense->reference }} @endif @include('expenses._chips', ['e' => $expense, 'noDup' => true])</p>
    </div>
    <div class="flex gap-2">
        @if ($ctx->can('expenses.manage') && ! $expense->voided_at)<a class="btn" href="{{ $bu }}/{{ $expense->mileageTrip ? 'mileage/'.$expense->mileageTrip->id : 'expenses/'.$expense->id }}/edit">Edit</a>@endif
        @if ($expense->journal_entry_id && $ctx->can('journals.view'))<a class="btn" href="{{ $bu }}/journals/{{ $expense->journal_entry_id }}">Ledger entry</a>@endif
        @if ($expense->receipt_document_id)<a class="btn" href="{{ $bu }}/expenses/{{ $expense->id }}/receipt" target="_blank" rel="noopener">View receipt</a>@endif
    </div>
</div>
@if ($expense->possible_duplicate)<div class="alert alert-warn mt-4">Another entry has the same vendor, date and amount. If this is a duplicate, void one of them.</div>@endif
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="card overflow-x-auto"><table class="tbl"><thead><tr><th>Category</th><th>Description</th><th class="num">Amount</th><th>Billable</th></tr></thead><tbody>
            @foreach ($expense->lines as $l)
                <tr><td>{{ $l->account->label() }}</td><td>{{ $l->description }}</td><td class="num">@money($l->amount_cents)</td>
                    <td>@if($l->billable) {!! $l->billed_invoice_id ? '<a href="'.e($bu).'/invoices/'.(int) $l->billed_invoice_id.'">Billed</a>' : '<span class="chip chip-amber">Unbilled</span>' !!} @endif</td></tr>
            @endforeach
        </tbody></table></div>
        @if ($expense->kind === 'bill')
        <div class="card overflow-x-auto"><div class="card-head"><h2>Payments</h2><span class="text-sm">Balance: <strong>@money($expense->balanceCents())</strong></span></div>
            <table class="tbl"><thead><tr><th>Date</th><th>From</th><th class="num">Amount</th></tr></thead><tbody>
            @forelse ($expense->vendorPayments as $vp)<tr><td>{{ $vp->payment_date->format('M j, Y') }}</td><td>{{ $vp->account->name }}</td><td class="num">@money($vp->amount_cents)</td></tr>
            @empty <tr><td colspan="3" class="text-slate-500">No payments yet.</td></tr> @endforelse
            </tbody></table>
            @if ($expense->balanceCents() > 0 && ! $expense->voided_at && $ctx->can('expenses.manage'))
            <form method="post" action="{{ $bu }}/expenses/{{ $expense->id }}/pay" class="card-body flex flex-wrap items-end gap-2">@csrf
                <div><label class="label" for="pay-amt">Amount</label><input class="input" id="pay-amt" name="amount" type="number" step="0.01" min="0.01" value="{{ number_format($expense->balanceCents() / 100, 2, '.', '') }}"></div>
                <div><label class="label" for="pay-acct">Paid from</label><select class="input" id="pay-acct" name="account_id">@foreach ($funding as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div><label class="label" for="pay-date">Date</label><input class="input" id="pay-date" type="date" name="date" value="{{ $biz->localToday()->toDateString() }}"></div>
                <button class="btn btn-primary">Record bill payment</button>
            </form>
            @endif
        </div>
        @endif
    </div>
    <aside class="space-y-4">
        <div class="card card-body text-sm"><dl class="dl">
            @if ($expense->paymentAccount)<dt>Paid from</dt><dd>{{ $expense->paymentAccount->name }}</dd>@endif
            @if ($expense->due_date)<dt>Due</dt><dd>{{ $expense->due_date->format('M j, Y') }}</dd>@endif
            @if ($expense->client)<dt>Client</dt><dd><a href="{{ $bu }}/clients/{{ $expense->client_id }}">{{ $expense->client->display_name }}</a></dd>@else<dt>Client</dt><dd class="text-slate-500">General business</dd>@endif
            @if ($expense->project)<dt>Project</dt><dd><a href="{{ $bu }}/projects/{{ $expense->project_id }}">{{ $expense->project->name }}</a></dd>@endif
            @if ($expense->description)<dt>Description</dt><dd>{{ $expense->description }}</dd>@endif
            @if ($expense->notes)<dt>Notes</dt><dd>{{ $expense->notes }}</dd>@endif
            @if ($expense->edited_at)<dt>Last edited</dt><dd>{{ $expense->edited_at->timezone($biz->timezone)->format('M j, Y g:i A') }}@if($expense->revision) · reposted {{ $expense->revision }}×@endif</dd>@endif
        </dl></div>
        @if ($expense->mileageTrip)
            @php($t = $expense->mileageTrip)
            <div class="card card-body text-sm"><h2 class="mb-2">Mileage</h2><dl class="dl">
                <dt>Route</dt><dd>{{ $t->from_location ?: '—' }} → {{ $t->to_location ?: '—' }}{{ $t->round_trip ? ' (round trip)' : '' }}</dd>
                <dt>Miles</dt><dd>{{ $t->totalMiles() }}</dd>
                <dt>IRS rate</dt><dd>{{ rtrim(rtrim(number_format($t->rate_millis / 10, 1), '0'), '.') }}¢ / mile</dd>
                <dt>Purpose</dt><dd>{{ $t->purpose }}</dd>
                @if ($t->vehicle)<dt>Vehicle</dt><dd>{{ $t->vehicle }}</dd>@endif
            </dl></div>
        @endif
        @if ($expense->source === 'import' && $expense->import_meta)
            <div class="card card-body text-sm"><h2 class="mb-2">Imported transaction</h2><dl class="dl">
                @foreach (['merchant' => 'Merchant', 'statement' => 'Statement text', 'category' => 'Source category', 'account' => 'Source account', 'tags' => 'Tags', 'entity' => 'Business entity', 'file' => 'File'] as $k => $label)
                    @if (!empty($expense->import_meta[$k]))<dt>{{ $label }}</dt><dd>{{ $expense->import_meta[$k] }}</dd>@endif
                @endforeach
                @if ($expense->importBatch)<dt>Batch</dt><dd><a href="{{ $bu }}/expenses?batch={{ $expense->import_batch_id }}">{{ $expense->importBatch->created_at->timezone($biz->timezone)->format('M j, Y') }} · {{ $expense->importBatch->imported_count }} rows</a></dd>@endif
            </dl></div>
        @endif
        @if ($ctx->can('expenses.manage') && ! $expense->voided_at)
            <form method="post" action="{{ $bu }}/expenses/{{ $expense->id }}/receipt" enctype="multipart/form-data" class="card card-body space-y-2">@csrf
                <label class="label" for="receipt">{{ $expense->receipt_document_id ? 'Replace receipt' : 'Attach receipt' }}</label>
                <input class="input" type="file" id="receipt" name="receipt" accept="application/pdf,image/png,image/jpeg" required>
                <button class="btn btn-sm">Upload</button>
                <p class="hint">Attaching a receipt never creates another expense.</p>
            </form>
            @if ($expense->client_id && $expense->lines->where('billable', true)->whereNull('billed_invoice_id')->isNotEmpty() && $ctx->can('invoices.manage'))
                <form method="post" action="{{ $bu }}/expenses/{{ $expense->id }}/bill-client" class="card card-body">@csrf<button class="btn">Add billable lines to a client invoice</button></form>
            @endif
            <form method="post" action="{{ $bu }}/expenses/{{ $expense->id }}/void" class="card card-body space-y-2" data-confirm="Void this entry? A reversing entry will be posted.">@csrf
                <label class="label" for="void-reason">Void</label><input class="input" id="void-reason" name="reason" placeholder="Reason" required>
                <button class="btn btn-danger btn-sm">Void</button>
            </form>
        @endif
    </aside>
</div>
@endsection
