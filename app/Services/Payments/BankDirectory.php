<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Bank name lookups for ABA routing numbers and SWIFT/BIC codes, with local format/checksum validation. */
class BankDirectory
{
    /** Bank codes (first 4 BIC characters) of large US banks, used when no SWIFT lookup service is configured. */
    private const KNOWN_BIC = [
        'CHAS' => 'JPMorgan Chase Bank, N.A.',
        'BOFA' => 'Bank of America, N.A.',
        'WFBI' => 'Wells Fargo Bank, N.A.',
        'CITI' => 'Citibank, N.A.',
        'PNCC' => 'PNC Bank, N.A.',
        'USBK' => 'U.S. Bank, N.A.',
        'MRMD' => 'HSBC Bank USA, N.A.',
        'IRVT' => 'The Bank of New York Mellon',
        'BKTR' => 'Deutsche Bank Trust Company Americas',
    ];

    /** Common routing numbers the online directory is missing. */
    private const KNOWN_ABA = [
        '021202337' => ['JPMorgan Chase Bank, N.A.', 'NJ'],
        '021100361' => ['JPMorgan Chase Bank, N.A.', 'CT'],
        '022300173' => ['JPMorgan Chase Bank, N.A.', 'NY'],
        '021000322' => ['Bank of America, N.A.', 'NY'],
        '021200339' => ['Bank of America, N.A.', 'NJ'],
        '011000138' => ['Bank of America, N.A.', 'MA'],
    ];

    public static function validRouting(?string $rn): bool
    {
        if (! preg_match('/^\d{9}$/', (string) $rn)) return false;
        $d = array_map('intval', str_split($rn));
        $sum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

        return $sum % 10 === 0;
    }

    public static function validSwift(?string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper((string) $code));
    }

    /** @return array{bank_name:string, location:?string}|null */
    public function routing(string $rn): ?array
    {
        if (! self::validRouting($rn)) return null;
        $url = (string) config('finance.banks.routing_lookup_url');
        $remote = $url === '' ? null : $this->cached('aba:'.$rn, function () use ($url, $rn) {
            $res = Http::timeout((int) config('finance.banks.timeout', 4))->acceptJson()->get(str_replace('{number}', $rn, $url));
            if ($res->status() === 404) return false;
            if (! $res->ok()) return null;
            $d = $res->json('data') ?? $res->json();
            $name = trim((string) ($d['bank_name'] ?? $d['customer_name'] ?? ''));
            if ($name === '') return false;

            return ['bank_name' => self::titleCase($name), 'location' => self::location($d['city'] ?? null, $d['state'] ?? null)];
        });
        if ($remote) return $remote;
        [$name, $state] = self::KNOWN_ABA[$rn] ?? [null, null];

        return $name ? ['bank_name' => $name, 'location' => $state] : null;
    }

    /** @return array{bank_name:string, location:?string}|null */
    public function swift(string $code): ?array
    {
        $code = strtoupper($code);
        if (! self::validSwift($code)) return null;
        $url = (string) config('finance.banks.swift_lookup_url');
        $key = (string) config('finance.banks.swift_lookup_key');
        $remote = $url !== '' ? $this->cached('bic:'.$code, function () use ($url, $key, $code) {
            $req = Http::timeout((int) config('finance.banks.timeout', 4))->acceptJson();
            if ($key !== '') $req = $req->withHeaders(['X-Api-Key' => $key]);
            $res = $req->get(str_replace('{code}', $code, $url));
            if ($res->status() === 404) return false;
            if (! $res->ok()) return null;
            $d = $res->json();
            if (is_array($d) && array_is_list($d)) $d = $d[0] ?? [];
            $d = $d['data'] ?? $d;
            $name = trim((string) ($d['bank_name'] ?? $d['bank'] ?? $d['name'] ?? ''));
            if ($name === '') return false;

            return ['bank_name' => self::titleCase($name), 'location' => self::location($d['city'] ?? null, $d['country'] ?? $d['country_code'] ?? null)];
        }) : null;
        if ($remote) return $remote;
        $known = self::KNOWN_BIC[substr($code, 0, 4)] ?? null;

        return $known && substr($code, 4, 2) === 'US' ? ['bank_name' => $known, 'location' => 'United States'] : null;
    }

    private function cached(string $key, \Closure $fetch): ?array
    {
        $key = 'bankdir:'.$key;
        $hit = Cache::get($key);
        if ($hit !== null) return $hit ?: null;
        try {
            $v = $fetch();
        } catch (\Throwable $e) {
            Log::warning('bank_directory.lookup_failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
        if ($v === null) return null; // transient failure: try again next time
        Cache::put($key, $v, $v ? now()->addDays(30) : now()->addDay());

        return $v ?: null;
    }

    private static function titleCase(string $s): string
    {
        if ($s !== strtoupper($s)) return $s;
        $t = ucwords(strtolower($s), " \t-/&(");

        return preg_replace_callback('/\b(Na|N\.a\.|Fsb|Usa|Us|Llc|Ssb|Fcu)\b/i', fn ($m) => strtoupper($m[0]), $t);
    }

    private static function location(?string $city, ?string $region): ?string
    {
        $city = $city ? ucwords(strtolower($city)) : null;
        $region = $region && strlen($region) <= 3 ? strtoupper($region) : $region;
        $loc = trim(implode(', ', array_filter([$city, $region])));

        return $loc !== '' ? $loc : null;
    }
}
