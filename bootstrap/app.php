<?php

use App\Exceptions\DomainException;
use App\Services\Auth\AuthUnavailable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustConfiguredProxies::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'central' => \App\Http\Middleware\EnsureCentralAuth::class,
            'business' => \App\Http\Middleware\ResolveBusiness::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'public.doc' => \App\Http\Middleware\PublicDocumentHeaders::class,
        ]);
        $middleware->encryptCookies();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'code', 'signature_data', 'payload', 'sig']);
        $exceptions->dontReport([DomainException::class]);
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->withInput($request->except(['signature_data', 'code']))->with('error', $e->getMessage());
        });
        $exceptions->render(function (AuthUnavailable $e, Request $request) {
            return response()->view('errors.auth-unavailable', [], 503);
        });
    })->create();
