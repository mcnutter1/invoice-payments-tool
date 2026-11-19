<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/** Server Manager helper contract (manage.mcnutt.cloud/integrate). HMAC-signed, machine-to-machine only. */
class ServerManagerController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->fail('unauthorized', 401);
        }

        $params = json_decode($request->getContent() ?: '{}', true);
        if (! is_array($params)) {
            return $this->fail('invalid json', 400);
        }

        try {
            return match ($params['action'] ?? '') {
                'health' => $this->ok($this->health()),
                'stats' => $this->ok($this->stats()),
                'version' => $this->ok(['version' => $this->version()]),
                'components' => $this->ok(['components' => $this->components()]),
                'clear_cache' => $this->clearCache(),
                'maintenance' => $this->maintenance($params),
                'migrate' => $this->migrate($params),
                default => $this->fail('unknown action', 400),
            };
        } catch (\Throwable $e) {
            Log::error('srvmgr action failed', ['action' => $params['action'] ?? null, 'exception' => $e]);

            return $this->fail('action failed', 500);
        }
    }

    private function authorized(Request $request): bool
    {
        $secret = (string) config('finance.srvmgr.token');
        if (strlen($secret) < 32) {
            return false;
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) config('finance.srvmgr.allowed_ips'))));
        if ($allowed && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return false;
        }

        $ts = (string) $request->header('X-Srvmgr-Timestamp', '');
        $nonce = (string) $request->header('X-Srvmgr-Nonce', '');
        $sig = (string) $request->header('X-Srvmgr-Signature', '');

        if ($sig !== '') {
            if (! ctype_digit($ts) || abs(time() - (int) $ts) > (int) config('finance.srvmgr.tolerance_seconds')
                || ! preg_match('/^[A-Fa-f0-9]{16,128}$/', $nonce)) {
                return false;
            }
            $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
            $canonical = implode("\n", [$ts, $nonce, $request->getMethod(), $path, hash('sha256', $request->getContent())]);
            $expected = 'v1='.rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');
            if (! hash_equals($expected, $sig)) {
                return false;
            }

            // Single-use nonce, recorded only after the signature checks out.
            return Cache::add('srvmgr:nonce:'.strtolower($nonce), 1, 2 * (int) config('finance.srvmgr.tolerance_seconds'));
        }

        $token = (string) $request->header('X-Srvmgr-Token', '');

        return config('finance.srvmgr.allow_legacy_token') && $token !== '' && hash_equals($secret, $token);
    }

    private function health(): array
    {
        $components = $this->components();
        $db = collect($components)->firstWhere('id', 'database')['status'] === 'ok';
        $healthy = collect($components)->every(fn ($c) => $c['status'] === 'ok');

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'db' => $db,
            'time' => now()->toIso8601String(),
            'maintenance' => app()->isDownForMaintenance(),
        ];
    }

    private function components(): array
    {
        $db = true;
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = false;
        }
        $out = [[
            'id' => 'database', 'name' => 'Database', 'kind' => 'datastore',
            'status' => $db ? 'ok' : 'down',
        ]];
        if (! $db) {
            return $out;
        }

        $beat = DB::table('job_heartbeats')->where('name', 'scheduler')->value('last_success_at');
        $age = $beat ? (int) now()->diffInMinutes($beat, true) : null;
        $out[] = [
            'id' => 'scheduler', 'name' => 'Cron scheduler', 'kind' => 'job',
            'status' => $age !== null && $age <= 5 ? 'ok' : 'degraded',
            'summary' => $age === null ? 'never run' : "last run {$age} min ago",
        ];

        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $out[] = [
            'id' => 'queue', 'name' => 'Job queue', 'kind' => 'queue',
            'status' => $failed > 0 ? 'degraded' : 'ok',
            'summary' => "{$pending} pending · {$failed} failed",
            'metrics' => ['pending' => $pending, 'failed' => $failed],
        ];

        $out[] = [
            'id' => 'storage', 'name' => 'Private storage', 'kind' => 'datastore',
            'status' => is_writable((string) config('finance.storage.private_root')) ? 'ok' : 'down',
        ];

        return $out;
    }

    private function stats(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'businesses' => DB::table('businesses')->count(),
            'clients' => DB::table('clients')->count(),
            'invoices' => DB::table('invoices')->count(),
            'jobs_pending' => DB::table('jobs')->count(),
            'jobs_failed' => DB::table('failed_jobs')->count(),
        ];
    }

    private function version(): string
    {
        $git = base_path('.git');
        $head = @file_get_contents($git.'/HEAD');
        if ($head === false) {
            return 'unknown';
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return $head;
        }
        $ref = substr($head, 5);
        $sha = @file_get_contents($git.'/'.$ref);
        if ($sha === false && ($packed = @file_get_contents($git.'/packed-refs')) !== false
            && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $m)) {
            $sha = $m[1];
        }

        return $sha ? trim($sha) : 'unknown';
    }

    private function clearCache(): JsonResponse
    {
        Artisan::call('optimize:clear');
        Audit::log(null, 'platform.srvmgr.clear_cache', actorType: 'srvmgr');

        return $this->ok(['cleared' => true]);
    }

    private function maintenance(array $params): JsonResponse
    {
        if (! array_key_exists('on', $params) || ! is_bool($params['on'])) {
            return $this->fail('param "on" must be a boolean', 400);
        }
        if ($params['on'] && ! app()->isDownForMaintenance()) {
            Artisan::call('down', ['--retry' => 60]);
        } elseif (! $params['on'] && app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
        Audit::log(null, 'platform.srvmgr.maintenance', after: ['on' => $params['on']], actorType: 'srvmgr');

        return $this->ok(['maintenance' => app()->isDownForMaintenance()]);
    }

    private function migrate(array $params): JsonResponse
    {
        if (($params['confirm'] ?? null) !== true) {
            return $this->fail('confirm:true required', 400);
        }
        $before = DB::table('migrations')->pluck('migration')->all();
        Artisan::call('migrate', ['--force' => true]);
        $applied = array_values(array_diff(DB::table('migrations')->pluck('migration')->all(), $before));
        Audit::log(null, 'platform.srvmgr.migrate', after: ['applied' => $applied], actorType: 'srvmgr');

        return $this->ok(['migrated' => true, 'applied' => $applied]);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data])->header('Cache-Control', 'no-store');
    }

    private function fail(string $error, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error], $status)->header('Cache-Control', 'no-store');
    }
}
