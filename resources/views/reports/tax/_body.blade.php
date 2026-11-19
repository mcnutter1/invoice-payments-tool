@php
    $M = fn ($c) => \App\Support\Money::format((int) $c);
    $p = $e['profile'];
    $s = $e['summary'];
    $formName = ['schedule_c' => 'Schedule C (Form 1040)', 'f1065' => 'Form 1065', 'f1120s' => 'Form 1120-S', 'f1120' => 'Form 1120'][$e['form']];
    $pctFmt = fn ($r) => rtrim(rtrim(number_format($r * 100, 3), '0'), '.').'%';
    $print = $print ?? false;
@endphp

<section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="metric"><div class="metric-label">Net business income (tax basis)</div><div class="metric-value">{{ $M($s['net_business_income']) }}</div>
        <div class="metric-note">{{ $e['projecting'] ? 'Actual to '.$e['as_of']->format('M j').' + expected to Dec 31' : 'Full year actual' }}</div></div>
    <div class="metric"><div class="metric-label">Tax from this business {{ $e['year'] }}</div><div class="metric-value">{{ $M($s['business_tax']) }}</div>
        <div class="metric-note">Estimates paid {{ $M($s['paid']) }} · {{ $s['balance'] >= 0 ? 'still to set aside '.$M($s['balance']) : 'ahead by '.$M(-$s['balance']) }}@if ($s['effective_rate'] !== null)<br>≈ {{ round($s['effective_rate'] * 100, 1) }}% of business profit @endif</div></div>
    <div class="metric"><div class="metric-label">Total tax — whole return</div><div class="metric-value">{{ $M($s['return_total']) }}</div>
        <div class="metric-note">Federal {{ $M($e['settlement']['federal']['total']) }} · State {{ $M($e['settlement']['state']['total']) }} · incl. W-2 &amp; other income@if($s['payroll'])<br>Payroll {{ $M($s['payroll']) }} paid separately @endif</div></div>
    <div class="metric"><div class="metric-label">{{ $s['return_balance'] >= 0 ? 'Owed with the return' : 'Expected refund' }}</div>
        <div @class(['metric-value', 'text-red-700' => $s['return_balance'] > 0, 'text-emerald-700' => $s['return_balance'] < 0])>{{ $M(abs($s['return_balance'])) }}</div>
        <div class="metric-note">After withholding and estimated payments · see <a href="#settlement">owed or refunded</a></div></div>
</section>

@if ($e['warnings'])
<div class="alert alert-warn mt-4"><ul class="list-disc space-y-1 pl-5">@foreach ($e['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></div>
@endif

@php
    $table = function (string $title, array $lines, ?string $sub = null) use ($M) {
        return view('reports.tax._lines', compact('title', 'lines', 'sub', 'M'))->render();
    };
@endphp

{!! $table('1 · Business income — '.$formName, $e['business'], $e['entity'][0].' · '.ucfirst($p['tax_method']).' method · '.$e['from']->format('M j').' – '.$e['to']->format('M j, Y')) !!}

@if ($e['projection'])
@php $pj = $e['projection']; @endphp
<section class="card mt-4">
    <div class="card-head"><h2>2 · Expected receipts through December 31</h2><span class="text-xs text-slate-500">Included above as expected receipts</span></div>
    <div class="card-body space-y-4 text-sm">
        @if (! $pj['cash'])<p class="text-slate-600">Accrual method: issued invoices are already income when invoiced, so open invoices are not added again. Only invoices still to be issued this year are projected.</p>@endif
        @if ($pj['open'])
        <table class="tbl"><thead><tr><th>Open invoice</th><th>Client</th><th>Due</th><th class="num">Open balance</th><th class="num">Income (excl. sales tax)</th></tr></thead><tbody>
            @foreach ($pj['open'] as $r)<tr><td>{{ $r['invoice']->number }}</td><td>{{ $r['invoice']->client->display_name }}</td><td>{{ $r['invoice']->due_date?->format('M j, Y') }}{{ $r['days_late'] ? ' · '.$r['days_late'].'d late' : '' }}</td><td class="num">{{ $M($r['open']) }}</td><td class="num">{{ $M($r['pre_tax']) }}</td></tr>@endforeach
            <tr class="total"><td colspan="4">Expected from open invoices</td><td class="num font-semibold">{{ $M($pj['open_total']) }}</td></tr>
        </tbody></table>
        @endif
        @if ($pj['doubtful'])
        <div><h3 class="text-sm font-semibold">Not counted</h3>
        <table class="tbl"><tbody>@foreach ($pj['doubtful'] as $r)<tr><td>{{ $r['invoice']->number }} · {{ $r['invoice']->client->display_name }}</td><td>{{ $r['reason'] }}</td><td class="num">{{ $M($r['pre_tax']) }}</td></tr>@endforeach</tbody></table>
        <p class="hint">Counted in the year it is actually collected (cash method).</p></div>
        @endif
        @if ($pj['recurring'])
        <table class="tbl"><thead><tr><th>Scheduled invoice</th><th>Client</th><th>Issue date</th><th>{{ $pj['cash'] ? 'Expected payment' : '' }}</th><th class="num">Amount (excl. tax)</th></tr></thead><tbody>
            @foreach ($pj['recurring'] as $r)<tr><td>{{ $r['schedule']->name }}</td><td>{{ $r['schedule']->client->display_name }}</td><td>{{ $r['date']->format('M j, Y') }}</td><td>{{ $pj['cash'] ? $r['expected']->format('M j, Y') : '' }}</td><td class="num">{{ $M($r['amount']) }}</td></tr>@endforeach
            <tr class="total"><td colspan="4">Expected from recurring schedules</td><td class="num font-semibold">{{ $M($pj['recurring_total']) }}</td></tr>
        </tbody></table>
        @endif
        @if (! $pj['open'] && ! $pj['recurring'])<p class="text-slate-500">No open invoices or scheduled billing expected before year end.</p>@endif
        @if ($pj['expenses'])<p>Projected expenses: <strong>{{ $M($pj['expenses']) }}</strong> ({{ $pj['expense_formula'] }}).</p>@endif
    </div>
</section>
@endif

<section class="card mt-4">
    <div class="card-head"><h2>3 · {{ $formName }} — amounts by return line</h2><span class="text-xs text-slate-500">For the preparer</span></div>
    <table class="tbl"><thead><tr><th>Line</th><th class="num">Amount</th></tr></thead><tbody>
        @foreach ($e['return_lines'] as $r)<tr><td>{{ $r['line'] }}</td><td class="num">{{ $M($r['amount']) }}</td></tr>@endforeach
    </tbody></table>
    <details class="border-t border-slate-100" @if($print) open @endif><summary class="card-head cursor-pointer list-none"><h3>Account detail</h3><span class="text-xs text-slate-500">How each ledger account was classified</span></summary>
        <table class="tbl"><thead><tr><th>Account</th><th>Category</th><th class="num">Book amount</th></tr></thead><tbody>
            @foreach ($e['books']['rows'] as $r)<tr><td>{{ $r['account']->code }} · {{ $r['account']->name }}</td><td>{{ str_replace('_', ' ', $r['category']) }}</td><td class="num">{{ $M($r['amount']) }}</td></tr>@endforeach
        </tbody></table></details>
</section>

@if ($e['corporate'])
    {!! $table('4 · Federal corporate tax — Form 1120', $e['federal']) !!}
    {!! $table('Owner’s personal federal tax on salary (Form 1040, informational)', $e['owner']['lines']) !!}
@else
    {!! $table('4 · Federal — owner’s Form 1040', $e['federal'], \App\Services\Tax\TaxProfile::FILING_STATUSES[$p['filing_status']].($p['dependents'] ? ' · '.$p['dependents'].' qualifying '.\Illuminate\Support\Str::plural('child', $p['dependents']) : '').(in_array($p['entity'], ['partnership', 's_corp'], true) ? ' · '.$p['ownership_pct'].'% owner' : '')) !!}
@endif

@php $bands = $e['owner']['bands'] ?? []; @endphp
@if (! $e['corporate'] && $bands)
<section class="card mt-4">
    <div class="card-head"><h2>Federal bracket detail</h2><span class="text-xs text-slate-500">{{ \App\Services\Tax\TaxProfile::FILING_STATUSES[$p['filing_status']] }} · marginal rate {{ $pctFmt($e['owner']['marginal_rate']) }}</span></div>
    <table class="tbl"><thead><tr><th>Rate</th><th>Taxable income band</th><th class="num">Income in band</th><th class="num">Tax</th></tr></thead><tbody>
        @foreach ($bands as $band)<tr @class(['text-slate-400' => ! $band['taxed']])><td>{{ $pctFmt($band['rate']) }}</td><td>{{ $M($band['from']) }} – {{ $band['to'] ? $M($band['to']) : 'and up' }}</td><td class="num">{{ $M($band['taxed']) }}</td><td class="num">{{ $M($band['tax']) }}</td></tr>@endforeach
    </tbody></table>
</section>
@endif

@if ($e['payroll'] && $e['payroll']['salary'] > 0)
    {!! $table('5 · Payroll taxes on officer salary (paid through payroll)', $e['payroll']['lines']) !!}
@endif

{!! $table('6 · State — '.$e['state']['name'], $e['state']['lines'] ?: [['label' => 'Not estimated', 'amount' => 0, 'formula' => 'No table loaded', 'ref' => '', 'strong' => false]]) !!}
@if ($e['state']['bands'])
<section class="card mt-4">
    <div class="card-head"><h2>State bracket detail</h2></div>
    <table class="tbl"><thead><tr><th>Rate</th><th>Band</th><th class="num">Income in band</th><th class="num">Tax</th></tr></thead><tbody>
        @foreach ($e['state']['bands'] as $band)<tr @class(['text-slate-400' => ! $band['taxed']])><td>{{ $pctFmt($band['rate']) }}</td><td>{{ $M($band['from']) }} – {{ $band['to'] ? $M($band['to']) : 'and up' }}</td><td class="num">{{ $M($band['taxed']) }}</td><td class="num">{{ $M($band['tax']) }}</td></tr>@endforeach
    </tbody></table>
</section>
@endif

@php
    $fed = $e['settlement']['federal'];
    $stS = $e['settlement']['state'];
    $owed = fn ($v) => $v >= 0 ? $M($v) : '('.$M(-$v).')';
@endphp
<section class="card mt-4 scroll-mt-4" id="settlement">
    <div class="card-head"><h2>7 · Owed or refunded — whole return</h2><span class="text-xs text-slate-500">Business + W-2 and other income, less what’s been paid</span></div>
    <table class="tbl"><thead><tr><th></th><th class="num">Federal</th><th class="num">State ({{ $e['state']['name'] }})</th><th class="num">Total</th></tr></thead><tbody>
        @foreach ([
            'other' => $e['corporate'] ? 'Owner’s personal tax on salary & other income' : 'Tax on W-2 wages & other income (without the business)',
            'business' => $e['corporate'] ? 'Corporate tax' : 'Extra tax from this business',
        ] as $k => $label)
            <tr><td>{{ $label }}</td><td class="num">{{ $M($fed[$k]) }}</td><td class="num">{{ $M($stS[$k]) }}</td><td class="num">{{ $M($fed[$k] + $stS[$k]) }}</td></tr>
        @endforeach
        <tr class="total"><td class="font-semibold">Total tax</td><td class="num font-semibold">{{ $M($fed['total']) }}</td><td class="num font-semibold">{{ $M($stS['total']) }}</td><td class="num font-semibold">{{ $M($fed['total'] + $stS['total']) }}</td></tr>
        <tr><td>Less: withholding (W-2 / payroll)</td><td class="num">−{{ $M($fed['withholding']) }}</td><td class="num">−{{ $M($stS['withholding']) }}</td><td class="num">−{{ $M($fed['withholding'] + $stS['withholding']) }}</td></tr>
        <tr><td>Less: estimated payments</td><td class="num">−{{ $M($fed['estimates']) }}</td><td class="num">−{{ $M($stS['estimates']) }}</td><td class="num">−{{ $M($fed['estimates'] + $stS['estimates']) }}</td></tr>
        <tr class="total"><td class="font-semibold">Owed / (refund)</td>
            @foreach ([$fed['balance'], $stS['balance'], $fed['balance'] + $stS['balance']] as $v)<td @class(['num font-semibold', 'text-red-700' => $v > 0, 'text-emerald-700' => $v < 0])>{{ $owed($v) }}</td>@endforeach</tr>
    </tbody></table>
    <p class="hint px-4 pb-4 sm:px-5">Withholding and estimated payments are the amounts entered in tax settings. Parentheses mean a refund.@if($s['payroll']) Payroll taxes ({{ $M($s['payroll']) }}) are paid through payroll and not included.@endif</p>
</section>

<section class="card mt-4">
    <div class="card-head"><h2>8 · Estimated payments &amp; deadlines</h2></div>
    <div class="card-body grid gap-6 text-sm lg:grid-cols-2">
        @foreach (['federal' => 'Federal (Form '.($e['corporate'] ? '1120-W' : '1040-ES').')', 'state' => 'State'] as $k => $label)
            @php $est = $e['estimates'][$k]; @endphp
            @if ($est)
            <div>
                <h3 class="font-semibold">{{ $label }}</h3>
                <dl class="dl mt-2">
                    <dt>Estimated liability</dt><dd>{{ $M($est['liability']) }}</dd>
                    <dt>Required annual payment</dt><dd>{{ $M($est['required']) }}<div class="text-xs text-slate-500">{{ $est['formula'] }}</div></dd>
                    <dt>Withholding</dt><dd>{{ $M($est['withholding']) }}</dd>
                    <dt>Estimates paid</dt><dd>{{ $M($est['paid']) }}</dd>
                </dl>
                @if ($est['needed'])
                <table class="tbl mt-2"><thead><tr><th>#</th><th>Due</th><th class="num">Cumulative required</th><th class="num">Suggested payment</th></tr></thead><tbody>
                    @foreach ($est['installments'] as $i)<tr @class(['text-slate-400' => $i['past']])><td>{{ $i['n'] }}</td><td>{{ $i['due']->format('D, M j, Y') }}</td><td class="num">{{ $M($i['cumulative']) }}</td><td class="num">{{ $i['past'] ? 'Passed' : $M($i['suggested']) }}</td></tr>@endforeach
                </tbody></table>
                <p class="hint mt-1">Balance expected with the return: {{ $M(max(0, $est['balance_at_filing'])) }}. Installments that were due before payments were made may carry an underpayment penalty (Form 2210).</p>
                @else
                <p class="hint mt-2">Below the estimated-tax threshold after withholding — quarterly payments not required.</p>
                @endif
            </div>
            @endif
        @endforeach
        <div class="lg:col-span-2"><h3 class="font-semibold">Return deadlines (calendar year)</h3>
            <ul class="mt-1 space-y-1">@foreach ($e['filing'] as $f)<li>{{ $f['label'] }}: <strong>{{ $f['due']->format('M j, Y') }}</strong> <span class="text-slate-500">(extended {{ $f['extended']->format('M j, Y') }}; an extension does not extend time to pay)</span></li>@endforeach</ul></div>
    </div>
</section>

<section class="card mt-4">
    <div class="card-head"><h2>8 · Tax tables, formulas &amp; sources</h2></div>
    <div class="card-body grid gap-6 text-sm lg:grid-cols-2">
        @foreach (['US' => $e['tables']['US'], $p['state'] => $e['tables']['state']] as $j => $tbl)
            @continue(! $tbl)
            @php $d = $tbl['data']; @endphp
            <div class="space-y-2">
                <h3 class="font-semibold">{{ $j === 'US' ? 'Federal' : ($d['name'] ?? $j) }} — {{ $tbl['year'] }}</h3>
                <p class="text-xs text-slate-500">Layers: @foreach ($tbl['layers'] as $l){{ $l['year'] }} {{ $l['source'] }}@if(! $loop->last), @endif @endforeach @if($tbl['carried']) · <span class="text-amber-700">carried forward: {{ implode(', ', $tbl['carried']) }}</span>@endif</p>
                @if (! empty($d['sources']))<p class="text-xs text-slate-500">References: {{ implode('; ', $d['sources']) }}</p>@endif
                @if (($d['type'] ?? '') === 'none')
                    <p>No personal income tax.</p>
                @else
                    @foreach (($d['brackets'] ?? []) as $status => $list)
                        <div><div class="text-xs font-medium uppercase text-slate-500">{{ \App\Services\Tax\TaxProfile::FILING_STATUSES[$status] ?? $status }}</div>
                            <div class="text-xs">@foreach ($list as $bk){{ $pctFmt($bk[1]) }} over ${{ number_format($bk[0]) }}@if(! $loop->last) · @endif @endforeach</div></div>
                    @endforeach
                    @if ($j === 'US')
                    <ul class="list-disc space-y-0.5 pl-5 text-xs">
                        <li>Standard deduction: single ${{ number_format($d['standard_deduction']['single']) }} · joint ${{ number_format($d['standard_deduction']['mfj']) }} · HoH ${{ number_format($d['standard_deduction']['hoh']) }}</li>
                        <li>Self-employment tax: {{ $pctFmt($d['se']['ss_rate']) }} Social Security up to ${{ number_format($d['ss_wage_base']) }} + {{ $pctFmt($d['se']['medicare_rate']) }} Medicare on {{ $pctFmt($d['se']['net_earnings_factor']) }} of net profit; half deductible; none under ${{ $d['se']['min_net_earnings'] }}</li>
                        <li>Additional Medicare: {{ $pctFmt($d['additional_medicare']['rate']) }} over ${{ number_format($d['additional_medicare']['threshold']['single']) }} single / ${{ number_format($d['additional_medicare']['threshold']['mfj']) }} joint</li>
                        <li>QBI (§199A): {{ $pctFmt($d['qbi']['rate']) }}; threshold ${{ number_format($d['qbi']['threshold']['single']) }} / ${{ number_format($d['qbi']['threshold']['mfj']) }}; phase-in ${{ number_format($d['qbi']['phase_in']['single']) }} / ${{ number_format($d['qbi']['phase_in']['mfj']) }}@if(! empty($d['qbi']['min_deduction'])); minimum ${{ $d['qbi']['min_deduction'] }}@endif</li>
                        <li>FICA: {{ $pctFmt($d['fica']['ss_rate']) }} + {{ $pctFmt($d['fica']['medicare_rate']) }} each for employer and employee; FUTA {{ $pctFmt($d['futa']['rate']) }} of first ${{ number_format($d['futa']['wage_base']) }}</li>
                        <li>Corporate rate {{ $pctFmt($d['corporate_rate']) }} · meals {{ $pctFmt($d['meals_deductible']) }} deductible · home office ${{ $d['home_office_simplified']['rate'] }}/sq ft up to {{ $d['home_office_simplified']['max_sqft'] }} sq ft</li>
                        <li>Estimated-tax safe harbor: {{ $pctFmt($d['estimated']['current_year_pct']) }} of current year, or {{ $pctFmt($d['estimated']['prior_year_pct']) }} of prior year ({{ $pctFmt($d['estimated']['high_agi_prior_year_pct']) }} if prior AGI over ${{ number_format($d['estimated']['high_agi']['single']) }})</li>
                    </ul>
                    @else
                    <ul class="list-disc space-y-0.5 pl-5 text-xs">
                        @if (isset($d['personal_exemption']))<li>Exemptions: ${{ number_format($d['personal_exemption']['taxpayer']) }} taxpayer, ${{ number_format($d['personal_exemption']['spouse']) }} spouse, ${{ number_format($d['personal_exemption']['dependent']) }} per dependent</li>@endif
                        @if (isset($d['corporate']['tiers']))<li>Corporate: @foreach ($d['corporate']['tiers'] as $tier){{ $pctFmt($tier[1]) }} if income ≥ ${{ number_format($tier[0]) }}@if(! $loop->last) · @endif @endforeach (rate applies to entire income)</li>@endif
                        @if (isset($d['estimated']))<li>Estimated-tax safe harbor: {{ $pctFmt($d['estimated']['current_year_pct']) }} of current year or {{ $pctFmt($d['estimated']['prior_year_pct']) }} of prior year</li>@endif
                    </ul>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</section>

<section class="card card-body mt-4 text-sm">
    <h2>Assumptions &amp; limits</h2>
    <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600">@foreach ($e['assumptions'] as $a)<li>{{ $a }}</li>@endforeach</ul>
    <p class="hint mt-3">This is an estimate prepared from the books for planning and for your tax preparer. It is not a tax return or tax advice; have a qualified professional review it before filing or paying.</p>
</section>
