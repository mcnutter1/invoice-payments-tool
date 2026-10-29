<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\Access\PermissionService;
use App\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Resolves /b/{business} and requires an active membership. Business context comes from the URL only. */
class ResolveBusiness
{
    public function __construct(private readonly PermissionService $perms) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ctx = app(Context::class);
        $business = $request->route('business');
        if (! $business instanceof Business) {
            $business = Business::where('ulid', (string) $business)->first();
        }
        if (! $business || ! $ctx->user) {
            abort(404);
        }
        $membership = $this->perms->activeMembership($ctx->user, $business);
        if (! $membership) {
            abort(404);
        }
        $ctx->business = $business;
        // Controllers read the tenant from Context, so drop it from positional route arguments.
        $request->route()->forgetParameter('business');
        $ctx->membership = $membership->load('roles');
        $ctx->permissions = $this->perms->permissionsFor($membership);
        $ctx->roleNames = $membership->roles->pluck('name')->all();
        if ($business->status !== 'active' && ! $request->isMethod('GET')) {
            abort(423, 'This business is suspended or archived; it is read-only.');
        }
        if ($ctx->user->last_business_id !== $business->id) {
            $ctx->user->forceFill(['last_business_id' => $business->id])->save();
        }
        View::share('biz', $business);
        View::share('bu', '/b/'.$business->ulid);
        View::share('ctx', $ctx);

        return $next($request);
    }
}
