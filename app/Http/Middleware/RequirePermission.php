<?php

namespace App\Http\Middleware;

use App\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('perm:invoices.issue') or 'perm:reports.ar.view|reports.pnl.view' (any). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $ctx = app(Context::class);
        if (! $ctx->canAny(...explode('|', $permissions))) {
            abort(403, 'You do not have permission to do that in this business.');
        }

        return $next($request);
    }
}
