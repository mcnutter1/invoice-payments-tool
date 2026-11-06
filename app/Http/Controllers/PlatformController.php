<?php

namespace App\Http\Controllers;

use App\Models\CentralRoleMapping;
use App\Services\Audit;
use App\Services\Auth\AuthGateway;
use App\Services\Notifications\NotificationGateway;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * Platform operations (no tenant financial access). Restricted to principals listed in FINANCE_PLATFORM_ADMINS
 * or users holding a mapped 'platform.operations' capability.
 */
class PlatformController extends Controller
{
    private function authorizePlatform(bool $manage = false): void
    {
        $user = $this->ctx()->user;
        $admins = array_filter(array_map('trim', explode(',', (string) config('finance.platform_admins'))));
        $isAdmin = in_array($user->central_principal_id, $admins, true);
        if ($manage) {
            abort_unless($isAdmin, 403);

            return;
        }
        abort_unless($isAdmin || $user->isPlatformAdmin() || app(\App\Services\Access\PermissionService::class)->hasCentralCapability($user, 'platform.operations'), 403);
    }

    public function index(AuthGateway $auth, NotificationGateway $notify)
    {
        $this->authorizePlatform();

        return view('platform.index', [
            'mappings' => CentralRoleMapping::orderBy('central_role')->get(),
            'capabilities' => Permissions::CENTRAL_CAPABILITIES,
            'auth' => $auth->health(),
            'notify' => $notify->health(),
            'isAdmin' => $this->ctx()->user->isPlatformAdmin(),
        ]);
    }

    public function storeMapping(Request $request)
    {
        $this->authorizePlatform(true);
        $data = $request->validate([
            'central_role' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'capability' => ['required', 'in:'.implode(',', array_keys(Permissions::CENTRAL_CAPABILITIES))],
        ]);
        $m = CentralRoleMapping::firstOrCreate($data + ['business_id' => null], ['created_by' => $this->ctx()->user->id]);
        Audit::log(null, 'platform.role_mapping_added', $m, null, $data);

        return back()->with('status', 'Mapping saved.');
    }

    public function deleteMapping(int $id)
    {
        $this->authorizePlatform(true);
        $m = CentralRoleMapping::findOrFail($id);
        Audit::log(null, 'platform.role_mapping_removed', $m, $m->toArray());
        $m->delete();

        return back()->with('status', 'Mapping removed.');
    }
}
