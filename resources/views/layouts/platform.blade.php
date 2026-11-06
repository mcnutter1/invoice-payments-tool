@php
    $user = app(\App\Support\Context::class)->user;
    $home = ($last = $user?->last_business_id ? \App\Models\Business::find($user->last_business_id) : null) ? '/b/'.$last->ulid : '/businesses';
    $path = '/'.request()->path();
    $links = ['/platform' => 'Platform operations'] + ($user?->isPlatformAdmin() ? ['/platform/backups' => 'Backups'] : []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Application settings') · McNutt Finance</title>
    <link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body class="min-h-screen bg-slate-50">
<a href="#main" class="sr-only-focusable fixed left-2 top-2 z-50 btn">Skip to content</a>
<header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-screen-2xl items-center gap-3 px-3 py-2 sm:px-5">
        <a href="{{ $home }}" class="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-slate-100" title="Back to McNutt Finance">
            <span class="flex h-8 w-8 items-center justify-center rounded bg-brand-700 text-sm font-bold text-white" aria-hidden="true">M</span>
            <span class="font-semibold text-slate-900">McNutt Finance</span>
        </a>
        <a href="{{ $home }}" class="btn btn-ghost btn-sm">← Back to app</a>
        <nav class="ml-2 hidden items-center gap-1 sm:flex" aria-label="Application settings">
            @foreach ($links as $href => $label)
                <a href="{{ $href }}" class="nav-link {{ $path === $href ? 'active' : '' }}" @if($path === $href) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @if ($user)
        <details class="relative ml-auto" data-menu>
            <summary class="btn btn-ghost list-none" aria-label="Account menu">
                <span class="hidden sm:inline">{{ $user->name ?: $user->email }}</span>
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700 sm:hidden" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name ?: $user->email, 0, 1)) }}</span>
            </summary>
            <div class="menu right-0">
                <div class="px-3 py-2 text-xs text-slate-500">{{ $user->email }}</div>
                <a href="{{ $home }}">Back to app</a>
                <a href="/businesses">All businesses</a>
                <form method="post" action="/auth/logout">@csrf<button type="submit">Sign out</button></form>
            </div>
        </details>
        @endif
    </div>
</header>
<main id="main" class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-5 sm:hidden">@include('partials.tabs', ['label' => 'Application settings', 'tabs' => collect($links)->map(fn ($l, $h) => [$l, $h, $path === $h])->values()->all()])</div>
    @include('partials.flash')
    @yield('content')
</main>
</body>
</html>
