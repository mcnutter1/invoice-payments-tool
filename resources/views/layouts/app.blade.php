<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Overview') · {{ $biz->name() }} · McNutt Finance</title>
    <link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body class="min-h-screen" data-business="{{ $biz->ulid }}">
<a href="#main" class="sr-only-focusable fixed left-2 top-2 z-50 btn">Skip to content</a>
@php
    $nav = [
        ['Overview', '', true, ''],
        ['Clients', '/clients', $ctx->can('clients.view'), 'clients'],
        ['Products & Services', '/catalog', $ctx->canAny('clients.view', 'catalog.manage'), 'catalog'],
        ['Invoices', '/invoices', $ctx->can('invoices.view'), 'invoices'],
        ['Recurring Billing', '/recurring', $ctx->can('recurring.view'), 'recurring'],
        ['Contracts', '/contracts', $ctx->can('contracts.view'), 'contracts'],
        ['Payments', '/payments', $ctx->can('payments.view'), 'payments'],
        ['Expenses', '/expenses', $ctx->can('expenses.view'), 'expenses'],
        ['Banking', '/banking', $ctx->can('banking.view'), 'banking'],
        ['Reports', '/reports', $ctx->canAny('reports.ar.view', 'reports.pnl.view'), 'reports'],
        ['Accounting', '/accounts', $ctx->can('journals.view'), 'accounts|journals|periods'],
        ['Settings', '/settings', $ctx->can('business.view'), 'settings|automation|audit'],
    ];
    $seg = request()->segment(3) ?? '';
@endphp
<header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-screen-2xl items-center gap-3 px-3 py-2 sm:px-5">
        <details class="relative lg:hidden" data-menu>
            <summary class="btn btn-ghost list-none" aria-label="Open navigation">☰</summary>
            <nav class="menu w-64" aria-label="Primary">
                @foreach ($nav as [$label, $path, $show])
                    @if ($show)<a href="{{ $bu.$path }}">{{ $label }}</a>@endif
                @endforeach
            </nav>
        </details>
        <details class="relative" data-menu id="biz-switcher">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md px-2 py-1 hover:bg-slate-100" aria-label="Switch business">
                @if ($biz->logo_document_id && $ctx->can('business.view'))
                    <img src="{{ $bu }}/settings/logo" alt="" class="h-8 w-8 rounded object-contain">
                @else
                    <span class="flex h-8 w-8 items-center justify-center rounded bg-brand-700 text-sm font-bold text-white" aria-hidden="true">{{ mb_substr($biz->name(), 0, 1) }}</span>
                @endif
                <span class="max-w-[14rem] truncate font-semibold text-slate-900">{{ $biz->name() }}</span>
                <span class="hidden text-xs text-slate-500 sm:inline">{{ implode(', ', $ctx->roleNames) }}</span>
                <span aria-hidden="true" class="text-slate-400">▾</span>
            </summary>
            <div class="menu w-80">
                <label class="sr-only" for="biz-filter">Find a business</label>
                <input id="biz-filter" class="input input-sm mb-1" placeholder="Find a business…" data-filter="#biz-list a">
                <div id="biz-list" class="max-h-72 overflow-auto">
                    @foreach ($ctx->user->memberships()->with('business', 'roles')->where('status', 'active')->get()->sortBy(fn ($m) => $m->business->name()) as $m)
                        <a href="/b/{{ $m->business->ulid }}/switch" class="flex justify-between gap-2" data-switch>
                            <span class="truncate {{ $m->business_id === $biz->id ? 'font-semibold' : '' }}">{{ $m->business->name() }}</span>
                            <span class="text-xs text-slate-500">{{ $m->roles->pluck('name')->implode(', ') }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="mt-1 border-t border-slate-100 pt-1">
                    <a href="/businesses">All businesses</a>
                    <a href="/businesses/new">+ Create business</a>
                </div>
            </div>
        </details>
        <form action="{{ $bu }}/search" method="get" class="ml-auto hidden max-w-xs flex-1 md:block" role="search">
            <label for="global-search" class="sr-only">Search clients, invoices and contracts</label>
            <input id="global-search" name="q" class="input input-sm" placeholder="Search…" value="{{ request()->is('*/search') ? request('q') : '' }}">
        </form>
        @if ($ctx->canAny('invoices.manage', 'clients.manage', 'payments.record', 'expenses.manage', 'contracts.manage'))
        <details class="relative md:ml-0 ml-auto" data-menu>
            <summary class="btn btn-primary list-none">+ New</summary>
            <div class="menu right-0">
                @if ($ctx->can('invoices.manage'))<a href="{{ $bu }}/invoices/new">Invoice</a>@endif
                @if ($ctx->can('clients.manage'))<a href="{{ $bu }}/clients/new">Client</a>@endif
                @if ($ctx->can('payments.record'))<a href="{{ $bu }}/payments/new">Receive payment</a>@endif
                @if ($ctx->can('expenses.manage'))<a href="{{ $bu }}/expenses/new">Expense</a><a href="{{ $bu }}/expenses/new?kind=bill">Bill</a>@endif
                @if ($ctx->can('contracts.manage'))<a href="{{ $bu }}/contracts/new">Contract</a>@endif
                @if ($ctx->can('recurring.manage'))<a href="{{ $bu }}/recurring/new">Recurring schedule</a>@endif
                @if ($ctx->can('banking.manage'))<a href="{{ $bu }}/movements/new">Transfer / owner activity</a>@endif
                @if ($ctx->can('journals.manage'))<a href="{{ $bu }}/journals/new">Journal entry</a>@endif
            </div>
        </details>
        @endif
        <details class="relative" data-menu>
            <summary class="btn btn-ghost list-none" aria-label="Account menu">{{ $ctx->user->name ?: $ctx->user->email }}</summary>
            <div class="menu right-0">
                <div class="px-3 py-2 text-xs text-slate-500">{{ $ctx->user->email }}</div>
                <form method="post" action="/auth/logout">@csrf<button type="submit">Sign out</button></form>
            </div>
        </details>
    </div>
</header>
<div class="mx-auto flex max-w-screen-2xl">
    <aside class="hidden w-56 shrink-0 border-r border-slate-200 bg-white lg:block">
        <nav class="sticky top-14 space-y-0.5 p-3" aria-label="Primary">
            @foreach ($nav as [$label, $path, $show, $match])
                @if ($show)
                    @php $active = $match === '' ? $seg === '' : in_array($seg, explode('|', $match), true); @endphp
                    <a href="{{ $bu.$path }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
    </aside>
    <main id="main" class="min-w-0 flex-1 p-3 sm:p-6">
        @include('partials.flash')
        @yield('content')
    </main>
</div>
</body>
</html>
