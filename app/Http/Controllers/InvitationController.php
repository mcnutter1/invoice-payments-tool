<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Invitation;
use App\Models\Membership;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

class InvitationController extends Controller
{
    private function find(string $token): Invitation
    {
        $inv = Invitation::with('role', 'business')->where('token_hash', hash('sha256', $token))->first();
        if (! $inv || ! $inv->isUsable()) {
            abort(404);
        }

        return $inv;
    }

    public function show(string $token)
    {
        $inv = $this->find($token);
        $user = $this->ctx()->user;

        return view('businesses.invitation', ['inv' => $inv, 'emailMatches' => strcasecmp((string) $user->email, $inv->email) === 0, 'token' => $token]);
    }

    public function accept(string $token)
    {
        $user = $this->ctx()->user;
        DB::transaction(function () use ($token, $user) {
            $inv = Invitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $inv || ! $inv->isUsable()) abort(404);
            if (strcasecmp((string) $user->email, $inv->email) !== 0) {
                throw new DomainException('This invitation was sent to a different email address than your McNutt Cloud account.');
            }
            $existing = Membership::where('business_id', $inv->business_id)->where('user_id', $user->id)->first();
            if ($existing && $existing->status === 'active') {
                throw new DomainException('You are already a member of this business.');
            }
            // Central identity emails are not documented as verified, so an owner/admin approves unless configured otherwise.
            $status = config('finance.auth.email_is_verified') ? 'active' : 'pending_approval';
            $m = $existing ?? new Membership(['business_id' => $inv->business_id, 'user_id' => $user->id]);
            $m->status = $status;
            $m->invited_by = $inv->invited_by;
            $m->joined_at = $status === 'active' ? now() : null;
            $m->removed_at = null;
            $m->save();
            $m->roles()->sync([$inv->role_id => ['business_id' => $inv->business_id]]);
            $inv->update(['accepted_at' => now(), 'accepted_user_id' => $user->id]);
            Audit::log($inv->business_id, 'member.invitation_accepted', $m, null, ['status' => $status, 'role_id' => $inv->role_id]);
        });

        return redirect('/businesses')->with('status', config('finance.auth.email_is_verified')
            ? 'Invitation accepted.'
            : 'Invitation accepted. An owner or admin must approve your access before you can open this business.');
    }
}
