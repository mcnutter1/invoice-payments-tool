<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ @filemtime(public_path('js/app.js')) }}" defer></script>
    @isset($accent)
        <style>.brand-bar{background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? $accent : '#1f5f8b' }};}</style>
    @endisset
</head>
<body class="min-h-screen bg-slate-100">
<div class="brand-bar h-1.5 bg-brand-700"></div>
<main id="main" class="mx-auto max-w-3xl px-3 py-6 sm:px-6 sm:py-10">
    @include('partials.flash')
    @yield('content')
    <p class="mt-10 text-center text-xs text-slate-500">Secure document link · No account needed · This page does not use third-party trackers.</p>
</main>
</body>
</html>
