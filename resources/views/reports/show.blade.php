@extends('layouts.app')
@section('title', $meta['title'])
@section('content')
@php
    $r = $meta['report'];
    $usesRange = ! in_array($r, ['balance-sheet', 'trial-balance', 'ar-aging', 'ap-aging', 'recurring-forecast', 'contract-values'], true);
    $usesAsOf = in_array($r, ['balance-sheet', 'trial-balance', 'ar-aging', 'ap-aging'], true);
    $usesBasis = in_array($r, ['profit-and-loss', 'general-ledger', 'expenses-by-category'], true);
    $q = request()->query();
@endphp
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>{{ $meta['title'] }}</h1>
        <p class="text-sm text-slate-500">{{ $biz->name() }} · USD ·
            @if ($usesRange) {{ \Illuminate\Support\Carbon::parse($meta['from'])->format('M j, Y') }} – {{ \Illuminate\Support\Carbon::parse($meta['to'])->format('M j, Y') }} @endif
            @if ($usesAsOf) As of {{ \Illuminate\Support\Carbon::parse($meta['asOf'])->format('M j, Y') }} @endif
            @if ($usesBasis) · <strong>{{ ucfirst($meta['basis']) }} basis</strong>@elseif(in_array($r, ['balance-sheet', 'trial-balance'])) · Accrual basis @endif
            · Generated {{ $meta['generatedAt']->format('M j, Y g:ia T') }}</p>
    </div>
    @if ($ctx->can('reports.export'))
    <div class="flex gap-2">
        <a class="btn" href="?{{ http_build_query(array_merge($q, ['format' => 'csv'])) }}">CSV</a>
        <a class="btn" href="?{{ http_build_query(array_merge($q, ['format' => 'pdf'])) }}">PDF</a>
    </div>
    @endif
</div>
<form method="get" class="card card-body mt-4 flex flex-wrap items-end gap-3">
    @if ($usesRange)
        <div><label class="label" for="range">Period</label><select class="input" id="range" name="range" data-range>
            @foreach (['ytd' => 'Fiscal year to date', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_year' => 'Last year', 'custom' => 'Custom'] as $k => $v)<option value="{{ $k }}" @selected($meta['preset'] === $k)>{{ $v }}</option>@endforeach
        </select></div>
        <div><label class="label" for="from">From</label><input class="input" type="date" id="from" name="from" value="{{ $meta['from'] }}" data-custom></div>
        <div><label class="label" for="to">To</label><input class="input" type="date" id="to" name="to" value="{{ $meta['to'] }}" data-custom></div>
    @endif
    @if ($usesAsOf)<div><label class="label" for="as_of">As of</label><input class="input" type="date" id="as_of" name="as_of" value="{{ $meta['asOf'] }}"></div>@endif
    @if ($usesBasis)<div><label class="label" for="basis">Basis</label><select class="input" id="basis" name="basis"><option value="accrual" @selected($meta['basis'] === 'accrual')>Accrual</option><option value="cash" @selected($meta['basis'] === 'cash')>Cash (management view)</option></select></div>@endif
    @if ($r === 'profit-and-loss')<label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="monthly" value="1" @checked(request()->boolean('monthly'))> Monthly columns</label>@endif
    @if ($r === 'general-ledger')<div><label class="label" for="account">Account</label><select class="input" id="account" name="account"><option value="">All accounts</option>@foreach ($meta['accounts'] as $a)<option value="{{ $a->id }}" @selected($meta['accountId'] === $a->id)>{{ $a->label() }}</option>@endforeach</select></div>@endif
    @if ($r === 'recurring-forecast')<div><label class="label" for="months">Months ahead</label><input class="input w-24" type="number" min="1" max="24" id="months" name="months" value="{{ request('months', 6) }}"></div>@endif
    <button class="btn btn-primary">Run report</button>
</form>
@if ($meta['basis'] === 'cash' && $usesBasis)
    <div class="alert alert-info mt-4">Cash basis is a management projection from the accrual ledger: invoice income and tax are recognized in proportion to cash applied, expenses when paid. It is not a tax return. See Accounting Policies.</div>
@endif
<div class="card mt-4 overflow-x-auto">
    @include('reports.body.'.$r, ['data' => $data, 'meta' => $meta, 'print' => false])
</div>
@endsection
