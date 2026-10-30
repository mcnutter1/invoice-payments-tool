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
        @php $ac = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? $accent : '#1f5f8b'; @endphp
        <meta name="theme-color" content="{{ $ac }}">
        <style>.brand-bar{background: {{ $ac }};} .btn-primary{background: {{ $ac }}; border-color: {{ $ac }};} .btn-primary:hover{filter: brightness(.92);} .summary-btn{border-color: {{ $ac }}; background: {{ $ac }};}</style>
    @endisset
</head>
<body class="min-h-screen bg-slate-100">
<div class="brand-bar h-1.5 bg-brand-700"></div>
<main id="main" class="mx-auto max-w-3xl px-3 py-5 sm:px-6 sm:py-10">
    @include('partials.flash')
    @yield('content')
    <footer class="mt-10 flex flex-col items-center gap-1 text-center text-xs text-slate-500">
        <span class="inline-flex items-center gap-1"><svg aria-hidden="true" viewBox="0 0 20 20" class="h-3.5 w-3.5 fill-current"><path d="M10 2a4 4 0 0 0-4 4v2H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V6a4 4 0 0 0-4-4Zm-2 6V6a2 2 0 1 1 4 0v2H8Z"/></svg> Secure, private link — no account needed</span>
        <span>Powered by McNutt Finance · No third-party trackers</span>
    </footer>
</main>
</body>
</html>
