<?php

namespace App\Services\Access;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;

class PermissionService
{
    /** @return list<string> */
    public function permissionsFor(?Membership $membership): array
    {
        if (! $membership || $membership->status !== 'active') {
            return [];
        }
        $membership->loadMissing('roles.permissions');
        $keys = [];
        foreach ($membership->roles as $role) {
            if ((int) $role->business_id !== (int) $membership->business_id) continue;
            foreach ($role->permissions as $p) {
                $keys[$p->key] = true;
            }
        }

        return array_keys($keys);
    }

    public function activeMembership(User $user, Business $business): ?Membership
    {
        return Membership::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function userCan(User $user, Business $business, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($this->activeMembership($user, $business)), true);
    }

    /** Central capability via explicit, audited mappings from verified central roles. Never grants tenant books. */
    public function hasCentralCapability(User $user, string $capability): bool
    {
        if ($capability === 'businesses.create' && config('finance.auth.business_creation') === 'all') {
            return true;
        }
        $roles = $user->central_roles ?? [];
        if (! $roles) return false;

        return \App\Models\CentralRoleMapping::whereIn('central_role', $roles)
            ->where('capability', $capability)
            ->whereNull('business_id')
            ->exists();
    }

    public function isOwner(Membership $m): bool
    {
        return $m->roles->contains(fn ($r) => $r->key === 'owner');
    }

    public function activeOwnerCount(Business $business): int
    {
        return Membership::where('business_id', $business->id)->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('key', 'owner'))->count();
    }
}
