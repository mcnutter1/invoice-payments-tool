<?php

namespace App\Http\Middleware;

use App\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $ctx = app(Context::class);
        $incoming = (string) $request->header('X-Correlation-Id');
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)) {
            $ctx->correlationId = $incoming;
        }

        /** @var Response $response */
        $response = $next($request);

        $loginOrigin = parse_url((string) config('finance.auth.login_base'), PHP_URL_SCHEME).'://'.parse_url((string) config('finance.auth.login_base'), PHP_URL_HOST);
        $h = $response->headers;
        $h->set('X-Correlation-Id', $ctx->correlationId);
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if (! $h->has('Referrer-Policy')) $h->set('Referrer-Policy', 'same-origin');
        if (! $h->has('Content-Security-Policy') && ! str_starts_with((string) $h->get('Content-Type'), 'application/pdf')) {
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-src 'self'",
                "frame-ancestors 'self'",
                "base-uri 'none'",
                "object-src 'none'",
                "form-action 'self' ".$loginOrigin,
            ]));
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if (! $h->has('Cache-Control') || ($request->hasSession() && $request->session()->has('central_auth'))) {
            $h->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
