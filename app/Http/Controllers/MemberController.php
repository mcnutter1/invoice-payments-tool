<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\PermissionService;
use App\Services\Audit;
use App\Services\Notifications\EmailComposer;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index()
    {
        $b = $this->biz();

        return view('settings.members', [
            'members' => Membership::with('user', 'roles')->where('business_id', $b->id)->whereIn('status', ['active', 'pending_approval'])->get(),
            'invitations' => Invitation::with('role')->where('business_id', $b->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->get(),
            'roles' => Role::where('business_id', $b->id)->get(),
            'isOwner' => $this->perms->isOwner($this->ctx()->membership),
        ]);
    }

    /** Admins cannot grant roles holding permissions they lack, and only owners grant the owner role. */
    private function assertGrantable(Role $role): void
    {
        $mine = $this->ctx()->permissions;
        if ($role->key === 'owner' && ! $this->perms->isOwner($this->ctx()->membership)) {
            throw new DomainException('Only owners can grant the owner role (use ownership transfer).');
        }
        $missing = array_diff($role->permissionKeys(), $mine);
        if ($missing) throw new DomainException('You cannot grant a role with permissions you do not have: '.implode(', ', $missing));
    }

    public function invite(Request $request, EmailComposer $email)
    {
        $b = $this->biz();
        $data = $request->validate(['email' => ['required', 'email'], 'role_id' => ['required', Rule::exists('roles', 'id')->where('business_id', $b->id)]]);
        $role = Role::with('permissions')->where('business_id', $b->id)->findOrFail($data['role_id']);
        $this->assertGrantable($role);
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $inv = Invitation::create([
            'business_id' => $b->id, 'email' => strtolower($data['email']), 'role_id' => $role->id,
            'token_hash' => hash('sha256', $raw), 'invited_by' => $this->ctx()->user->id, 'expires_at' => now()->addDays(7),
        ]);
        $email->queueBusinessInvitation($inv->load('role', 'inviter'), url('/invite/'.$raw));
        Audit::log($b, 'member.invited', $inv, null, ['email' => $inv->email, 'role' => $role->key]);

        return back()->with('status', 'Invitation sent to '.$inv->email.'.');
    }

    public function revokeInvitation(int $id)
    {
        $inv = Invitation::where('business_id', $this->biz()->id)->findOrFail($id);
        $inv->update(['revoked_at' => now()]);
        Audit::log($this->biz(), 'member.invitation_revoked', $inv);

        return back()->with('status', 'Invitation revoked.');
    }

    public function approve(int $id)
    {
        $m = Membership::where('business_id', $this->biz()->id)->where('status', 'pending_approval')->findOrFail($id);
        $m->load('roles.permissions');
        foreach ($m->roles as $r) $this->assertGrantable($r);
        $m->update(['status' => 'active', 'joined_at' => now()]);
        Audit::log($this->biz(), 'member.approved', $m);

        return back()->with('status', 'Member approved.');
    }

    public function updateRoles(Request $request, int $id)
    {
        $b = $this->biz();
        $m = Membership::with('roles.permissions')->where('business_id', $b->id)->findOrFail($id);
        $ids = array_map('intval', (array) $request->input('roles', []));
        $roles = Role::with('permissions')->where('business_id', $b->id)->whereIn('id', $ids)->get();
        if ($roles->isEmpty()) throw new DomainException('Choose at least one role.');
        foreach ($roles as $r) $this->assertGrantable($r);
        foreach ($m->roles as $r) $this->assertGrantable($r); // cannot modify someone holding powers you lack
        DB::transaction(function () use ($m, $roles, $b) {
            $wasOwner = $m->roles->contains('key', 'owner');
            $m->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['business_id' => $b->id]])->all());
            if ($wasOwner && ! $roles->contains('key', 'owner') && $this->perms->activeOwnerCount($b) < 1) {
                throw new DomainException('A business must keep at least one active owner.');
            }
            Audit::log($b, 'member.roles_changed', $m, ['roles' => $m->roles->pluck('key')], ['roles' => $roles->pluck('key')]);
        });

        return back()->with('status', 'Roles updated.');
    }

    public function remove(int $id)
    {
        $b = $this->biz();
        $m = Membership::with('roles.permissions')->where('business_id', $b->id)->findOrFail($id);
        foreach ($m->roles as $r) $this->assertGrantable($r);
        DB::transaction(function () use ($m, $b) {
            $m->update(['status' => 'removed', 'removed_at' => now(), 'removed_by' => $this->ctx()->user->id]);
            if ($this->perms->activeOwnerCount($b) < 1) throw new DomainException('You cannot remove the last active owner.');
            Audit::log($b, 'member.removed', $m);
        });

        return back()->with('status', 'Member removed. Their access ended immediately.');
    }

    public function transferOwnership(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate(['membership_id' => ['required', 'integer'], 'confirm' => ['required', 'in:TRANSFER'], 'keep_admin' => ['nullable', 'boolean']]);
        $target = Membership::where('business_id', $b->id)->where('status', 'active')->findOrFail($data['membership_id']);
        $me = $this->ctx()->membership;
        if ($target->id === $me->id) throw new DomainException('Choose another member.');
        $owner = Role::where('business_id', $b->id)->where('key', 'owner')->firstOrFail();
        $admin = Role::where('business_id', $b->id)->where('key', 'admin')->firstOrFail();
        DB::transaction(function () use ($target, $me, $owner, $admin, $b, $request) {
            $target->roles()->syncWithoutDetaching([$owner->id => ['business_id' => $b->id]]);
            $me->roles()->detach($owner->id);
            if ($request->boolean('keep_admin')) $me->roles()->syncWithoutDetaching([$admin->id => ['business_id' => $b->id]]);
            Audit::log($b, 'business.ownership_transferred', $b, ['owner_membership' => $me->id], ['owner_membership' => $target->id]);
        });

        return $this->to('/settings', 'Ownership transferred.');
    }

    public function roles()
    {
        $b = $this->biz();

        return view('settings.roles', [
            'roles' => Role::with('permissions')->where('business_id', $b->id)->get(),
            'catalog' => Permissions::ALL,
        ]);
    }

    public function storeRole(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'permissions' => ['nullable', 'array'], 'permissions.*' => [Rule::in(array_keys(Permissions::ALL))]]);
        $keys = $data['permissions'] ?? [];
        $missing = array_diff($keys, $this->ctx()->permissions);
        if ($missing) throw new DomainException('You cannot grant permissions you do not have: '.implode(', ', $missing));
        if (in_array('ownership.transfer', $keys, true)) throw new DomainException('Ownership transfer belongs to the owner role only.');
        $role = Role::create(['business_id' => $b->id, 'key' => 'custom_'.substr(bin2hex(random_bytes(4)), 0, 8), 'name' => $data['name']]);
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        Audit::log($b, 'role.created', $role, null, ['permissions' => $keys]);

        return back()->with('status', 'Role created.');
    }

    public function updateRole(Request $request, int $id)
    {
        $b = $this->biz();
        $role = Role::with('permissions')->where('business_id', $b->id)->findOrFail($id);
        if ($role->key === 'owner') throw new DomainException('The owner role cannot be changed.');
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'permissions' => ['nullable', 'array'], 'permissions.*' => [Rule::in(array_keys(Permissions::ALL))]]);
        $keys = $data['permissions'] ?? [];
        $changed = array_merge(array_diff($keys, $role->permissionKeys()), array_diff($role->permissionKeys(), $keys));
        $missing = array_diff($changed, $this->ctx()->permissions);
        if ($missing) throw new DomainException('You cannot grant or revoke permissions you do not have: '.implode(', ', $missing));
        if (in_array('ownership.transfer', $keys, true)) throw new DomainException('Ownership transfer belongs to the owner role only.');
        $before = $role->permissionKeys();
        $role->update(['name' => $data['name']]);
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        Audit::log($b, 'role.updated', $role, ['permissions' => $before], ['permissions' => $keys]);

        return back()->with('status', 'Role saved.');
    }
}
