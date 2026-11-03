<?php

namespace App\Services\PublicAccess;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Approximate IP location and network owner (e.g. "AS15169 Google LLC"), cached per IP. */
class GeoIp
{
    /** @return array{location: ?string, network: ?string} */
    public function lookup(?string $ip): array
    {
        $none = ['location' => null, 'network' => null];
        if (! config('finance.geoip.enabled') || ! $ip
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $none;
        }
        $key = 'geoip:'.$ip;
        if (is_array($hit = Cache::get($key))) return $hit;

        try {
            $req = Http::timeout((int) config('finance.geoip.timeout', 2))->acceptJson();
            if ($token = config('finance.geoip.token')) $req = $req->withToken($token);
            $res = $req->get('https://ipinfo.io/'.rawurlencode($ip).'/json');
            if (! $res->successful()) {
                Cache::put($key, $none, now()->addHour());

                return $none;
            }
            $d = $res->json() ?: [];
            $out = [
                'location' => mb_substr(implode(', ', array_filter([$d['city'] ?? null, $d['region'] ?? null, $d['country'] ?? null])), 0, 120) ?: null,
                'network' => mb_substr((string) ($d['org'] ?? ''), 0, 120) ?: null,
            ];
            Cache::put($key, $out, now()->addDays((int) config('finance.geoip.cache_days', 30)));

            return $out;
        } catch (Throwable) {
            Cache::put($key, $none, now()->addHour());

            return $none;
        }
    }
}
