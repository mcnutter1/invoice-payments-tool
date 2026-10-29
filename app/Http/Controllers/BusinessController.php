<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Membership;
use App\Services\Access\BusinessProvisioner;
use App\Services\Access\PermissionService;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function home()
    {
        $user = $this->ctx()->user;
        if ($user->last_business_id) {
            $b = Business::find($user->last_business_id);
            if ($b && $this->perms->activeMembership($user, $b)) {
                return redirect('/b/'.$b->ulid);
            }
        }

        return redirect('/businesses');
    }

    public function index()
    {
        $user = $this->ctx()->user;
        $memberships = Membership::with(['business', 'roles'])->where('user_id', $user->id)->whereIn('status', ['active', 'pending_approval'])->get()
            ->sortBy(fn ($m) => $m->business->name());

        return view('businesses.index', [
            'memberships' => $memberships,
            'canCreate' => $this->perms->hasCentralCapability($user, 'businesses.create'),
        ]);
    }

    public function create()
    {
        abort_unless($this->perms->hasCentralCapability($this->ctx()->user, 'businesses.create'), 403);

        return view('businesses.create');
    }

    public function store(Request $request, BusinessProvisioner $provisioner)
    {
        abort_unless($this->perms->hasCentralCapability($this->ctx()->user, 'businesses.create'), 403);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'display_name' => ['nullable', 'string', 'max:200'],
            'entity_type' => ['nullable', 'in:sole_proprietor,llc,s_corp,c_corp,partnership,other'],
            'timezone' => ['required', 'timezone:all'],
            'public_email' => ['nullable', 'email'],
        ]);
        $data['display_name'] = $data['display_name'] ?: $data['legal_name'];
        $business = $provisioner->create($this->ctx()->user, $data);

        return redirect('/b/'.$business->ulid.'/settings')->with('status', 'Business created. Complete your profile and payment instructions.');
    }

    public function switch()
    {
        return redirect('/b/'.$this->biz()->ulid);
    }
}
