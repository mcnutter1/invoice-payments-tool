<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Liveness/readiness. Reveals no secrets, versions, or tenant data. */
class HealthController extends Controller
{
    public function show()
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'failed';
        }
        $beat = DB::table('job_heartbeats')->where('name', 'scheduler')->value('last_success_at');
        $checks['cron'] = $beat && now()->diffInMinutes($beat, true) <= 5 ? 'ok' : 'stale';
        $checks['storage'] = is_writable(config('finance.storage.private_root')) ? 'ok' : 'failed';
        $ok = $checks['database'] === 'ok' && $checks['storage'] === 'ok';

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
