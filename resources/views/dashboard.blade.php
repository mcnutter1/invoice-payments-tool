@extends('layouts.app')
@section('title', 'Overview')
@section('content')
@use('App\Support\Chart')
@use('App\Support\Money')
@php
    $first = explode(' ', trim($ctx->user?->label() ?? ''))[0] ?? '';
    $hour = now()->timezone($biz->timezone)->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $rangeLabel = \Illuminate\Support\Carbon::parse($from)->format('M j').' – '.\Illuminate\Support\Carbon::parse($to)->format('M j, Y');
    $presets = ['this_month' => 'Month', 'last_month' => 'Last month', 'this_quarter' => 'Quarter', 'ytd' => 'Year to date', 'last_year' => 'Last year'];
    $compare = in_array($preset, ['ytd', 'last_year'], true) ? 'vs. same period last year' : 'vs. previous period';
    $chip = function (?float $d, bool $upIsGood = true) {
        if ($d === null) return '';
        $good = $upIsGood ? $d >= 0 : $d <= 0;
        $arrow = $d >= 0 ? '↑' : '↓';
        return '<span class="trend '.($good ? 'trend-up' : 'trend-down').'">'.$arrow.' '.number_format(abs($d), 1).'%</span>';
    };

    $attention = array_values(array_filter([
        $claims->count() ? ['n' => $claims->count(), 'text' => 'payment report(s) to confirm', 'href' => $bu.'/payments#claims', 'dot' => 'bg-amber-400'] : null,
        ($ar['overdue_count'] ?? 0) ? ['n' => $ar['overdue_count'], 'text' => 'overdue invoice(s)', 'href' => $bu.'/invoices?status=overdue', 'dot' => 'bg-red-500'] : null,
        $awaitingSignature->count() ? ['n' => $awaitingSignature->count(), 'text' => 'agreement(s) awaiting signature', 'href' => $bu.'/contracts?status=sent', 'dot' => 'bg-violet-500'] : null,
        $deliveryIssues ? ['n' => $deliveryIssues, 'text' => 'email(s) need review', 'href' => $bu.'/automation', 'dot' => 'bg-orange-500'] : null,
        $reviewRuns ? ['n' => $reviewRuns, 'text' => 'missed recurring period(s)', 'href' => $bu.'/recurring', 'dot' => 'bg-sky-500'] : null,
    ]));

    // Revenue chart geometry
    if ($trend) {
        $W = 720; $H = 190; $padX = 14;
        $max = Chart::niceMax(max(1, max(array_merge($trend['collected'], $trend['invoiced']))));
        $pc = Chart::points($trend['collected'], $W, $H, $max, $padX);
        $pi = Chart::points($trend['invoiced'], $W, $H, $max, $padX);
        $lineC = Chart::smoothPath($pc, $H);
        $areaC = $lineC.' L'.end($pc)[0].','.$H.' L'.$pc[0][0].','.$H.' Z';
        $lineI = Chart::smoothPath($pi, $H);
        $trendCollected = array_sum($trend['collected']);
        $slot = ($W - 2 * $padX) / max(1, count($pc) - 1);
    }
    $agingTones = ['current' => 'bg-emerald-500', 'late1' => 'bg-amber-400', 'late2' => 'bg-orange-500', 'late3' => 'bg-red-500', 'late4' => 'bg-red-700'];
    $upNext = collect()
        ->concat($claims->take(4)->map(fn ($c) => ['dot' => 'bg-amber-400', 'title' => 'Payment reported · '.$c->invoice->client->display_name, 'sub' => $c->invoice->number.' · '.ucfirst($c->method), 'meta' => Money::format($c->reported_amount_cents), 'href' => $bu.'/claims/'.$c->id]))
        ->concat($awaitingSignature->take(4)->map(fn ($c) => ['dot' => 'bg-violet-500', 'title' => 'Awaiting signature · '.$c->title, 'sub' => $c->client->display_name.' · '.$c->number, 'meta' => str_replace('_', ' ', $c->status), 'href' => $bu.'/contracts/'.$c->id]))
        ->concat($dueSoon->take(4)->map(fn ($s) => ['dot' => 'bg-sky-500', 'title' => $s->name, 'sub' => $s->client->display_name.' · recurring invoice', 'meta' => $s->next_run_date?->format('M j'), 'href' => $bu.'/recurring/'.$s->id]))
        ->take(7);
@endphp

<header class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm font-medium text-slate-500">{{ $greeting }}{{ $first ? ', '.$first : '' }}</p>
        <h1 class="mt-0.5 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $biz->name() }}</h1>
    </div>
    <div class="w-full overflow-x-auto sm:w-auto">
        <nav class="seg" aria-label="Date range">
            @foreach ($presets as $k => $v)
                <a href="?range={{ $k }}" @class(['seg-item', 'seg-active' => $preset === $k]) @if($preset === $k) aria-current="page" @endif>{{ $v }}</a>
            @endforeach
        </nav>
    </div>
</header>

@if ($attention)
<div class="mt-6 flex flex-wrap gap-2" aria-label="Needs attention">
    @foreach ($attention as $a)
        <a href="{{ $a['href'] }}" class="pill"><span class="h-2 w-2 rounded-full {{ $a['dot'] }}" aria-hidden="true"></span><span class="font-semibold text-slate-900">{{ $a['n'] }}</span> {{ $a['text'] }}<span class="text-slate-400" aria-hidden="true">→</span></a>
    @endforeach
</div>
@endif

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    @if ($metrics)
    <section class="panel lg:col-span-2" aria-labelledby="rev-h">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="rev-h" class="panel-label">Cash collected</h2>
                <div class="mt-1 flex flex-wrap items-baseline gap-3">
                    <span class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums sm:text-4xl">@money($metrics['collected'])</span>
                    {!! $chip(Chart::delta($metrics['collected'], $previous['collected'] ?? 0)) !!}
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $rangeLabel }} · {{ $compare }}</p>
            </div>
            <div class="sm:text-right">
                <div class="panel-label">Invoiced</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-slate-700">@money($metrics['issued'])</div>
                {!! $chip(Chart::delta($metrics['issued'], $previous['issued'] ?? 0)) !!}
            </div>
        </div>
        <figure class="mt-6">
            @php $vbH = $H + 44; $yTop = fn ($y) => round(($y + 16) / $vbH * 100, 2); @endphp
            <div class="relative">
            <span class="pointer-events-none absolute left-0 -translate-y-full pb-0.5 text-[11px] font-medium text-slate-400" style="top: {{ $yTop(0) }}%" aria-hidden="true">{{ Chart::compact($max) }}</span>
            <span class="pointer-events-none absolute left-0 -translate-y-full pb-0.5 text-[11px] font-medium text-slate-400" style="top: {{ $yTop($H / 2) }}%" aria-hidden="true">{{ Chart::compact((int) ($max / 2)) }}</span>
            <svg viewBox="0 -16 {{ $W }} {{ $H + 44 }}" class="h-56 w-full text-brand-600 sm:h-64" role="img" aria-label="Collected and invoiced per month, last 12 months" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="g-collected" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="currentColor" stop-opacity="0.28"/>
                        <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                @foreach ([0, 0.5, 1] as $g)
                    <line x1="0" x2="{{ $W }}" y1="{{ $H - $g * $H }}" y2="{{ $H - $g * $H }}" stroke="#e2e8f0" stroke-width="1" @if($g > 0) stroke-dasharray="3 5" @endif vector-effect="non-scaling-stroke"/>
                @endforeach
                <path d="{{ $areaC }}" fill="url(#g-collected)"/>
                <path d="{{ $lineI }}" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 5" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                <path d="{{ $lineC }}" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                @foreach ($trend['labels'] as $i => $label)
                    <g>
                        <title>{{ $label }} · collected {{ Money::format($trend['collected'][$i]) }} · invoiced {{ Money::format($trend['invoiced'][$i]) }}</title>
                        <rect x="{{ max(0, $pc[$i][0] - $slot / 2) }}" y="-16" width="{{ $slot }}" height="{{ $H + 16 }}" fill="transparent"/>
                    </g>
                @endforeach
            </svg>
            </div>
            <div class="-mt-6 flex justify-between px-1 text-[11px] font-medium text-slate-400 sm:px-2" aria-hidden="true">
                @foreach ($trend['labels'] as $i => $label)<span @class(['hidden sm:inline' => $i % 2 === 1])>{{ $label }}</span>@endforeach
            </div>
            <figcaption class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-brand-600"></span>Collected</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-0 w-4 border-t-2 border-dashed border-slate-400"></span>Invoiced</span>
                <span class="ml-auto">Last 12 months</span>
            </figcaption>
            @if ($trendCollected === 0 && array_sum($trend['invoiced']) === 0)
                <p class="mt-2 text-center text-sm text-slate-500">Your revenue trend appears here once you issue invoices and record payments.</p>
            @endif
        </figure>
    </section>
    @endif

    @if ($ar)
    <section class="panel {{ $metrics ? '' : 'lg:col-span-3' }}" aria-labelledby="ar-h">
        <h2 id="ar-h" class="panel-label">Receivables</h2>
        <div class="mt-1 text-3xl font-semibold tracking-tight tabular-nums text-slate-900">@money($ar['outstanding'])</div>
        <p class="mt-1 text-xs {{ $ar['overdue'] > 0 ? 'font-medium text-red-600' : 'text-slate-500' }}">{{ $ar['overdue'] > 0 ? Money::format($ar['overdue']).' overdue' : 'Nothing overdue' }}</p>

        @php $agTotal = max(1, $ar['aging']['total']); @endphp
        <div class="mt-6 flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Receivables by age">
            @foreach ($ar['aging']['buckets'] as $bk)
                @if ($bk['cents'] > 0)<span class="{{ $agingTones[$bk['tone']] }} h-full" style="width: {{ round($bk['cents'] / $agTotal * 100, 2) }}%"></span>@endif
            @endforeach
        </div>
        <ul class="mt-5 space-y-2.5 text-sm">
            @foreach ($ar['aging']['buckets'] as $bk)
                <li class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2 text-slate-600"><span class="h-2 w-2 rounded-full {{ $agingTones[$bk['tone']] }}"></span>{{ $bk['label'] }}</span>
                    <span class="tabular-nums {{ $bk['cents'] ? 'font-medium text-slate-900' : 'text-slate-400' }}">@money($bk['cents'])</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
            <span class="text-slate-500">Avg. days to get paid</span>
            <span class="font-semibold text-slate-900">{{ $ar['days_to_pay'] !== null ? $ar['days_to_pay'].' days' : '—' }}</span>
        </div>
        <a href="{{ $bu }}/reports/ar-aging" class="mt-4 inline-flex text-sm font-medium">View aging report →</a>
    </section>
    @endif
</div>

@if ($metrics || $revenue)
<section class="panel mt-6 grid grid-cols-2 p-0 sm:p-0 lg:grid-cols-4" aria-label="Key figures">
    @if ($metrics)
    @php $margin = $metrics['income'] > 0 ? round($metrics['net'] / $metrics['income'] * 100) : null; @endphp
    <a href="{{ $bu }}/reports/profit-and-loss?range={{ $preset }}&basis={{ $metrics['basis'] }}" class="kpi">
        <span class="panel-label">Net profit</span>
        <span @class(['kpi-value', 'text-red-600' => $metrics['net'] < 0])>@money($metrics['net'])</span>
        <span class="flex items-center gap-2">{!! $chip(Chart::delta($metrics['net'], $previous['net'] ?? 0)) !!}@if($margin !== null)<span class="text-xs text-slate-500">{{ $margin }}% margin</span>@endif</span>
    </a>
    <a href="{{ $bu }}/reports/profit-and-loss?range={{ $preset }}&basis={{ $metrics['basis'] }}" class="kpi">
        <span class="panel-label">Expenses</span>
        <span class="kpi-value">@money($metrics['expenses'])</span>
        <span>{!! $chip(Chart::delta($metrics['expenses'], $previous['expenses'] ?? 0), false) !!}</span>
    </a>
    @endif
    @if ($revenue)
    <a href="{{ $bu }}/recurring" class="kpi">
        <span class="panel-label">Annual recurring revenue</span>
        <span class="kpi-value">@money($revenue['arr'])</span>
        <span class="text-xs text-slate-500">@money($revenue['mrr']) / month · {{ $revenue['count'] }} plan(s)</span>
    </a>
    <a href="{{ $bu }}/recurring" class="kpi">
        <span class="panel-label">Recurring clients</span>
        <span class="kpi-value">{{ count($revenue['by_client']) }}</span>
        <span class="text-xs text-slate-500">{{ $dueSoon->count() }} invoice(s) in the next 14 days</span>
    </a>
    @endif
</section>
@endif

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    @if ($ctx->can('payments.view'))
    <section class="panel" aria-labelledby="top-h">
        <div class="flex items-center justify-between"><h2 id="top-h" class="panel-title">Top clients</h2><span class="text-xs text-slate-400">Paid, last 12 months</span></div>
        @php $topMax = max(1, collect($topClients)->max('cents') ?? 1); @endphp
        <ul class="mt-5 space-y-4">
            @forelse ($topClients as $tc)
                <li>
                    <div class="flex items-center justify-between gap-3 text-sm"><a href="{{ $bu }}/clients/{{ $tc['id'] }}" class="truncate font-medium text-slate-800 no-underline hover:text-brand-700">{{ $tc['name'] }}</a><span class="tabular-nums text-slate-600">{{ Chart::compact($tc['cents']) }}</span></div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, round($tc['cents'] / $topMax * 100)) }}%"></div></div>
                </li>
            @empty
                <li class="py-6 text-center text-sm text-slate-500">No payments in the last 12 months.</li>
            @endforelse
        </ul>
    </section>
    @endif

    <section class="panel" aria-labelledby="next-h">
        <h2 id="next-h" class="panel-title">Up next</h2>
        <ul class="mt-3 divide-y divide-slate-100">
            @forelse ($upNext as $item)
                <li><a href="{{ $item['href'] }}" class="flex items-center gap-3 py-3 no-underline">
                    <span class="h-2 w-2 flex-none rounded-full {{ $item['dot'] }}" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800">{{ $item['title'] }}</span><span class="block truncate text-xs text-slate-500">{{ $item['sub'] }}</span></span>
                    <span class="text-xs font-medium capitalize text-slate-500">{{ $item['meta'] }}</span>
                </a></li>
            @empty
                <li class="py-8 text-center text-sm text-slate-500">You're all caught up.</li>
            @endforelse
        </ul>
    </section>

    @if ($ctx->can('invoices.view'))
    <section class="panel" aria-labelledby="inv-h">
        <div class="flex items-center justify-between"><h2 id="inv-h" class="panel-title">Recent invoices</h2><a href="{{ $bu }}/invoices" class="text-sm font-medium">View all</a></div>
        <ul class="mt-3 divide-y divide-slate-100">
            @forelse ($recentInvoices as $i)
                <li><a href="{{ $bu }}/invoices/{{ $i->id }}" class="flex items-center gap-3 py-3 no-underline">
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800">{{ $i->client->display_name }}</span><span class="block text-xs text-slate-500">{{ $i->displayNumber() }}{{ $i->due_date ? ' · due '.$i->due_date->format('M j') : '' }}</span></span>
                    <span class="text-right"><span class="block text-sm font-medium tabular-nums text-slate-900">@money($i->total_cents)</span>@include('partials.invoice-status', ['i' => $i])</span>
                </a></li>
            @empty
                <li class="py-8 text-center text-sm text-slate-500">No invoices yet. @if($ctx->can('invoices.manage'))<a href="{{ $bu }}/invoices/new">Create your first invoice</a>.@endif</li>
            @endforelse
        </ul>
    </section>
    @endif
</div>
@endsection
