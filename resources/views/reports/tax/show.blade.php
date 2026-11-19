@extends('layouts.app')
@section('title', 'Tax estimate '.$e['year'])
@section('content')
@php
    $p = $e['profile'];
    $d = fn ($k) => $p[$k] ? number_format($p[$k] / 100, 2, '.', '') : '';
    $money = function (string $k, string $label, string $hint = '') use ($d) {
        return '<div><label class="label" for="tx-'.$k.'">'.e($label).'</label><input class="input" id="tx-'.$k.'" name="'.$k.'" type="number" step="0.01" min="0" value="'.e(old($k, $d($k))).'" placeholder="0.00">'.($hint ? '<p class="hint">'.e($hint).'</p>' : '').'</div>';
    };
    $check = fn (string $k, string $label) => '<label class="flex items-start gap-2 text-sm"><input type="hidden" name="'.$k.'" value="0"><input class="check mt-0.5" type="checkbox" name="'.$k.'" value="1" '.($p[$k] ? 'checked' : '').'> <span>'.e($label).'</span></label>';
@endphp
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1>Tax estimate · {{ $e['year'] }}</h1>
        <p class="text-sm text-slate-500">{{ $biz->name() }} · {{ $e['entity'][0] }} · {{ $e['entity'][1] }} · {{ $p['state'] }} · Generated {{ $generatedAt->format('M j, Y g:ia T') }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <form method="get" class="flex gap-2"><select class="input w-auto" name="year" aria-label="Tax year" data-autosubmit>@foreach ($years as $y)<option value="{{ $y }}" @selected($y === $e['year'])>{{ $y }}</option>@endforeach</select><noscript><button class="btn">Go</button></noscript></form>
        @if ($ctx->can('reports.export'))
            <a class="btn" href="?{{ http_build_query(['year' => $e['year'], 'format' => 'csv']) }}">CSV</a>
            <a class="btn btn-primary" href="?{{ http_build_query(['year' => $e['year'], 'format' => 'pdf']) }}">PDF for accountant</a>
        @endif
    </div>
</div>

<details class="card mt-4" id="tax-settings" @if(! $p['configured'] || $errors->any()) open @endif>
    <summary class="card-head cursor-pointer list-none"><h2>Tax settings</h2><span class="text-xs text-slate-500">{{ $p['configured'] ? 'Entity, owner and '.$e['year'].' payments — click to edit' : 'Review before relying on the estimate' }}</span></summary>
    <form method="post" action="{{ $bu }}/reports/tax-estimate" class="card-body space-y-6">@csrf
        <input type="hidden" name="year" value="{{ $e['year'] }}">
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @disabled(! $canEdit)>
            <legend class="mb-2 text-sm font-semibold">Business &amp; owner</legend>
            <div class="sm:col-span-2"><label class="label" for="tx-entity">How the business is taxed</label><select class="input" id="tx-entity" name="entity">@foreach (\App\Services\Tax\TaxProfile::ENTITIES as $k => [$l, $f])<option value="{{ $k }}" @selected($p['entity'] === $k)>{{ $l }} — {{ $f }}</option>@endforeach</select></div>
            <div><label class="label" for="tx-fs">Owner’s filing status</label><select class="input" id="tx-fs" name="filing_status">@foreach (\App\Services\Tax\TaxProfile::FILING_STATUSES as $k => $l)<option value="{{ $k }}" @selected($p['filing_status'] === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label class="label" for="tx-state">State of residence</label><input class="input uppercase" id="tx-state" name="state" maxlength="2" value="{{ $p['state'] }}"></div>
            <div><label class="label" for="tx-method">Accounting method</label><select class="input" id="tx-method" name="tax_method"><option value="cash" @selected($p['tax_method'] === 'cash')>Cash (most small businesses)</option><option value="accrual" @selected($p['tax_method'] === 'accrual')>Accrual</option></select></div>
            <div><label class="label" for="tx-own">Owner’s share (%)</label><input class="input" id="tx-own" name="ownership_pct" type="number" step="0.01" min="0.01" max="100" value="{{ $p['ownership_pct'] }}"><p class="hint">Partnerships and S corporations.</p></div>
            <div><label class="label" for="tx-owners">Number of owners</label><input class="input" id="tx-owners" name="owner_count" type="number" min="1" value="{{ $p['owner_count'] }}"></div>
            <div><label class="label" for="tx-dep">Qualifying children under 17</label><input class="input" id="tx-dep" name="dependents" type="number" min="0" max="20" value="{{ $p['dependents'] }}"></div>
            <div><label class="label" for="tx-ho">Home office (sq ft)</label><input class="input" id="tx-ho" name="home_office_sqft" type="number" min="0" value="{{ $p['home_office_sqft'] ?: '' }}"><p class="hint">Simplified method; sole proprietors / single-member LLCs.</p></div>
            <div class="space-y-2 sm:col-span-2 lg:col-span-3">{!! $check('sstb', 'Specified service business (consulting, law, accounting…) — limits the QBI deduction at higher incomes') !!}{!! $check('partner_se', 'Partners/members pay self-employment tax on their share (general partner / active LLC member)') !!}{!! $check('section_179', 'Expense equipment purchases this year (§179 / 100% bonus depreciation)') !!}</div>
        </fieldset>
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @disabled(! $canEdit)>
            <legend class="mb-2 text-sm font-semibold">Projection to December 31</legend>
            <div class="space-y-2 sm:col-span-2 lg:col-span-3">{!! $check('include_open_invoices', 'Count open invoices expected to be collected this year (cash method)') !!}{!! $check('include_recurring', 'Count recurring invoices scheduled for the rest of the year') !!}{!! $check('project_expenses', 'Project expenses for the rest of the year at the year-to-date daily rate') !!}</div>
            <div><label class="label" for="tx-doubt">Treat as doubtful after (days past due)</label><input class="input" id="tx-doubt" name="doubtful_after_days" type="number" min="0" value="{{ $p['doubtful_after_days'] }}"></div>
        </fieldset>
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @disabled(! $canEdit)>
            <legend class="mb-2 text-sm font-semibold">{{ $e['year'] }} owner compensation &amp; other income</legend>
            {!! $money('officer_salary', 'Officer salary (S/C corp)', 'Reasonable compensation paid via W-2') !!}
            <div class="self-center">{!! $check('salary_in_books', 'Salary and employer payroll taxes are already recorded as expenses') !!}</div>
            {!! $money('w2_wages_paid', 'Other W-2 wages the business pays', 'Employees other than the owner (QBI wage limit)') !!}
            {!! $money('other_wages', 'Owner’s W-2 wages from other jobs') !!}
            {!! $money('spouse_wages', 'Spouse’s wages (joint returns)') !!}
            {!! $money('other_income', 'Other taxable income', 'Interest, rental, etc. (ordinary rates)') !!}
            {!! $money('retirement', 'SEP / solo 401(k) contributions') !!}
            {!! $money('health_insurance', 'Self-employed health insurance premiums') !!}
            {!! $money('itemized', 'Itemized deductions (if itemizing)', 'Used only if larger than the standard deduction') !!}
        </fieldset>
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @disabled(! $canEdit)>
            <legend class="mb-2 text-sm font-semibold">{{ $e['year'] }} payments &amp; prior year (for safe harbor)</legend>
            {!! $money('fed_estimates_paid', 'Federal estimates paid') !!}
            {!! $money('fed_withholding', 'Federal withholding') !!}
            {!! $money('state_estimates_paid', 'State estimates paid') !!}
            {!! $money('state_withholding', 'State withholding') !!}
            {!! $money('prior_year_fed_tax', ($e['year'] - 1).' total federal tax') !!}
            {!! $money('prior_year_agi', ($e['year'] - 1).' AGI') !!}
            {!! $money('prior_year_state_tax', ($e['year'] - 1).' state tax') !!}
        </fieldset>
        @if ($canEdit)<div><button class="btn btn-primary">Save and recalculate</button></div>@endif
    </form>
</details>

<div class="mt-4">@include('reports.tax._body', ['e' => $e, 'print' => false])</div>
@endsection
