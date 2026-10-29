<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/** Trust forwarded headers only from proxies listed in TRUSTED_PROXIES (comma-separated IPs/CIDRs). */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) config('finance.trusted_proxies')))));

        return $list ?: null;
    }
}
