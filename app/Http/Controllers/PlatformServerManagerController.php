<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\ServerManager\Pairing;
use Illuminate\Http\Request;

/** Platform → Server Manager: pair this app with manage.mcnutt.cloud without handling secrets by hand. */
class PlatformServerManagerController extends Controller
{
    public function __construct(private readonly Pairing $pairing) {}

    private function authorizeAdmin(): void
    {
        abort_unless($this->ctx()->user?->isPlatformAdmin(), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();
        $state = $this->pairing->state();
        $pending = ! empty($state['payload']) && empty($state['paired_at']) && (int) ($state['expires'] ?? 0) > time() ? $state : null;

        return view('platform.server-manager', [
            'state' => $state,
            'paired' => $this->pairing->isPaired(),
            'envSecret' => $this->pairing->envSecret(),
            'pending' => $pending,
            'managerUrl' => $this->pairing->managerUrl(),
            'pubkey' => $this->pairing->pinnedPubkey(),
            'pubkeyFromEnv' => (string) config('finance.srvmgr.manager_pubkey') !== '',
            'helperUrl' => url('/srvmgr/helper'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'manager_url' => ['required', 'url', 'starts_with:https://', 'max:200'],
            'manager_pubkey' => ['nullable', 'string', 'max:100'],
        ]);
        $this->pairing->saveManager($data['manager_url'], $data['manager_pubkey'] ?? null);
        Audit::log(null, 'platform.srvmgr.settings_saved', after: ['manager_url' => $data['manager_url'], 'pubkey_set' => ! empty($data['manager_pubkey'])]);

        return back()->with('status', 'Server Manager settings saved.');
    }

    public function unlock(Request $request)
    {
        $this->authorizeAdmin();
        $token = $request->validate(['unlock_token' => ['required', 'string', 'max:4000']])['unlock_token'];
        $state = $this->pairing->unlock($token, url('/srvmgr/helper'), $request->getHost());
        Audit::log(null, 'platform.srvmgr.unlocked', after: ['jti' => $state['unlock_jti']]);

        return back()->with('status', 'Unlock token verified. Copy the enrollment payload into Server Manager to finish pairing.');
    }

    public function reset()
    {
        $this->authorizeAdmin();
        $this->pairing->reset();
        Audit::log(null, 'platform.srvmgr.pairing_reset');

        return back()->with('status', 'Pairing reset and the shared secret rotated. Server Manager can no longer connect until you pair again.');
    }
}
