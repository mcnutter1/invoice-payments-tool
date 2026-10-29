@extends('layouts.app')
@section('title', 'New journal entry')
@section('content')
@include('accounting._tabs')
<form method="post" action="{{ $bu }}/journals" class="mt-4 grid max-w-4xl gap-4" data-journal-form>
    @csrf
    <div class="card card-body grid gap-4 sm:grid-cols-3">
        <div><label class="label" for="entry_date">Date</label><input class="input" type="date" id="entry_date" name="entry_date" required value="{{ old('entry_date', $biz->localToday()->toDateString()) }}"></div>
        <div class="sm:col-span-2"><label class="label" for="memo">Memo</label><input class="input" id="memo" name="memo" required value="{{ old('memo') }}"></div>
        <div class="sm:col-span-3"><label class="label" for="reason">Reason (audit)</label><input class="input" id="reason" name="reason" required value="{{ old('reason') }}" placeholder="e.g. Accountant adjustment: defer prepaid services"></div>
    </div>
    <div class="card overflow-x-auto">
        <table class="tbl" data-lines><thead><tr><th>Account</th><th>Description</th><th class="w-36">Debit</th><th class="w-36">Credit</th><th class="w-10"></th></tr></thead><tbody>
            @foreach (old('lines', [[], []]) as $i => $l)@include('accounting._journal-line', ['i' => $i, 'l' => $l])@endforeach
        </tbody></table>
        <template id="line-template">@include('accounting._journal-line', ['i' => '__I__', 'l' => []])</template>
        <div class="card-body flex flex-wrap items-center gap-4 pt-3 text-sm"><button type="button" class="btn btn-sm" data-add-line>+ Line</button>
            <span>Debits <strong data-jdr>$0.00</strong></span><span>Credits <strong data-jcr>$0.00</strong></span><span data-jdiff class="font-semibold"></span></div>
    </div>
    <p class="hint">Use manual journals for accountant corrections (deferrals, accruals, reclassifications). A/R and A/P are control accounts and can only change through invoices, payments and bills.</p>
    <div><button class="btn btn-primary" data-confirm="Post this journal entry? It cannot be edited afterward.">Post entry</button></div>
</form>
@endsection
