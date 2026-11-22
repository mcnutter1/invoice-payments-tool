<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Data API: `X-API-Key: fin_…` or `Authorization: Bearer fin_…`; optional required scope as the middleware parameter. */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = (string) ($request->header('X-API-Key') ?: $request->bearerToken());
        $key = $token !== '' ? ApiKey::findActive($token) : null;
        if (! $key) {
            return self::error('unauthorized', 'Missing, invalid, expired or revoked API key.', 401);
        }
        if ($scope && ! $key->allows($scope)) {
            return self::error('forbidden', "This API key does not have the {$scope} scope.", 403);
        }

        $ctx = app(Context::class);
        $ctx->business = $key->business;
        $ctx->actorType = 'api';
        $request->attributes->set('api_key', $key);
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Correlation-Id', $ctx->correlationId);

        return $response;
    }

    public static function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'correlation_id' => app(Context::class)->correlationId]], $status)
            ->header('Cache-Control', 'no-store');
    }
}
