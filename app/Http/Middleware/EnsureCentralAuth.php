<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthUnavailable;
use App\Services\Auth\CentralSession;
use App\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires a verified central identity; revalidates within the configured window and fails closed. */
class EnsureCentralAuth
{
    public function __construct(private readonly CentralSession $session) {}

    public function handle(Request $request, Closure $next, ?string $fresh = null): Response
    {
        $user = $this->session->user();
        if (! $user) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return $request->expectsJson() ? response()->json(['error' => 'unauthenticated'], 401) : redirect('/auth/login');
        }
        $maxAge = $fresh ? 60 : (int) config('finance.auth.revalidate_seconds');
        try {
            if (! $this->session->ensureFresh($maxAge)) {
                return redirect('/auth/login')->with('status', 'Your session ended. Please sign in again.');
            }
        } catch (AuthUnavailable) {
            return response()->view('errors.auth-unavailable', [], 503);
        }
        app(Context::class)->user = $user->fresh();

        return $next($request);
    }
}
