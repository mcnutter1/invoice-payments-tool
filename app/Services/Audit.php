<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Business;
use App\Services\PublicAccess\GeoIp;
use App\Support\Context;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    private const REDACT = ['token', 'secret', 'password', 'sig', 'otp', 'tax_id', 'api_key', 'session_token', 'encrypted_body', 'otp_hash'];

    public static function log(
        Business|int|null $business,
        string $action,
        ?Model $object = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $actorType = null,
    ): AuditEvent {
        $ctx = app(Context::class);
        $req = app()->runningInConsole() ? null : request();
        $geo = $req ? app(GeoIp::class)->lookup($req->ip()) : ['location' => null, 'network' => null];

        return AuditEvent::create([
            'business_id' => $business instanceof Business ? $business->id : $business,
            'actor_user_id' => $ctx->user?->id,
            'actor_type' => $actorType ?? ($ctx->user ? 'user' : $ctx->actorType),
            'action' => $action,
            'object_type' => $object ? class_basename($object) : null,
            'object_id' => $object?->getKey(),
            'correlation_id' => $ctx->correlationId,
            'ip' => $req?->ip(),
            'user_agent' => $req ? substr((string) $req->userAgent(), 0, 255) : null,
            'location' => $geo['location'],
            'network' => $geo['network'],
            'before' => self::sanitize($before),
            'after' => self::sanitize($after),
            'reason' => $reason,
        ]);
    }

    public static function sanitize(?array $data): ?array
    {
        if ($data === null) return null;
        foreach ($data as $k => $v) {
            foreach (self::REDACT as $needle) {
                if (is_string($k) && str_contains(strtolower($k), $needle)) {
                    $data[$k] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($v)) $data[$k] = self::sanitize($v);
        }

        return $data;
    }

    /** Diff two attribute arrays, returning [before, after] limited to changed keys. */
    public static function diff(array $before, array $after): array
    {
        $b = [];
        $a = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) != $v) {
                $b[$k] = $before[$k] ?? null;
                $a[$k] = $v;
            }
        }

        return [$b, $a];
    }
}
