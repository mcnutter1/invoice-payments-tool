<?php

namespace App\Http\Controllers;

use App\Models\MileageRate;
use App\Models\PlatformSetting;
use App\Services\Audit;
use App\Services\Expenses\MileageRateSync;
use Illuminate\Http\Request;

/** Application-level IRS standard mileage rates (shared by all businesses). */
class PlatformMileageController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless($this->ctx()->user?->isPlatformAdmin(), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();

        return view('platform.mileage-rates', [
            'rates' => MileageRate::orderByDesc('effective_from')->get(),
            'lastSync' => PlatformSetting::read('mileage.last_sync'),
            'source' => MileageRateSync::URL,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'unique:mileage_rates,effective_from'],
            'cents' => ['required', 'numeric', 'min:1', 'max:500'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $rate = MileageRate::create(['effective_from' => $data['effective_from'], 'business_rate_millis' => (int) round($data['cents'] * 10), 'source' => 'manual', 'note' => $data['note'] ?? null]);
        Audit::log(null, 'mileage_rate.added', $rate, null, $rate->only(['effective_from', 'business_rate_millis']));

        return back()->with('status', 'Rate added.');
    }

    public function destroy(int $id)
    {
        $this->authorizeAdmin();
        $rate = MileageRate::where('source', 'manual')->findOrFail($id);
        Audit::log(null, 'mileage_rate.deleted', $rate, $rate->only(['effective_from', 'business_rate_millis']));
        $rate->delete();

        return back()->with('status', 'Rate removed. Trips already recorded keep their rate until re-priced.');
    }

    public function sync(MileageRateSync $sync)
    {
        $this->authorizeAdmin();
        $r = $sync->sync();

        return back()->with('status', $r['ok'] ? ($r['added'] ? 'Added: '.implode(', ', $r['added']).'.' : 'IRS rates are up to date.') : 'Check failed: '.$r['error']);
    }
}
