@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<h1>Reports</h1>
<p class="mt-1 text-sm text-slate-500">All reports are in USD, use {{ $biz->timezone }} day boundaries, exclude drafts and payment claims, and drill down to source lines.</p>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($reports as $key => [$title, $perm])
        <a href="{{ $bu }}/reports/{{ $key }}" class="metric"><div class="font-semibold text-slate-900">{{ $title }}</div>
            <div class="metric-note">{{ [
                'profit-and-loss' => 'Cash or accrual, monthly columns',
                'balance-sheet' => 'Accrual, as of any date',
                'trial-balance' => 'Debits = credits check',
                'general-ledger' => 'Every posted line with running balance',
                'ar-aging' => 'Open receivables by days past due',
                'ap-aging' => 'Unpaid bills by days past due',
                'invoice-summary' => 'Issued, collected, outstanding',
                'sales-by-client' => 'Accrual revenue by client',
                'sales-by-item' => 'Revenue by product/service',
                'expenses-by-category' => 'Spending by account',
                'expenses-by-vendor' => 'Spending by vendor',
                'sales-tax' => 'Liability and collected-tax schedule',
                'recurring-forecast' => 'Upcoming scheduled billing (forecast)',
                'contract-values' => 'Committed vs invoiced vs collected',
            ][$key] ?? '' }}</div></a>
    @endforeach
</div>
@endsection
