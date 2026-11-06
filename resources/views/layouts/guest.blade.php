<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'McNutt Finance')</title>
    <link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body class="min-h-screen bg-slate-50">
<main id="main" class="mx-auto {{ ($wide ?? false) ? 'max-w-5xl' : 'max-w-3xl' }} px-4 py-10">
    <div class="mb-8 flex items-center gap-2">
        <span class="flex h-9 w-9 items-center justify-center rounded bg-brand-700 font-bold text-white" aria-hidden="true">M</span>
        <span class="text-lg font-semibold text-slate-900">McNutt Finance</span>
        @if (session('central_auth'))
            <form method="post" action="/auth/logout" class="ml-auto">@csrf<button class="btn btn-ghost btn-sm">Sign out</button></form>
        @endif
    </div>
    @include('partials.flash')
    @yield('content')
</main>
</body>
</html>
