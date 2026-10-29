<?php

namespace App\Services\PublicAccess;

use App\Models\Business;
use App\Models\PublicToken;
use App\Support\Context;
use Illuminate\Http\Request;

/**
 * Opaque bearer links (256-bit), stored only as SHA-256 hashes. URL tokens are exchanged for a
 * session-scoped grant and redirected to a clean URL (docs/PUBLIC_LINK_SECURITY.md).
 */
class PublicLinkService
{
    private const SESSION_KEY = 'public_grants';

    /** @return array{0:PublicToken,1:string} [token, raw] */
    public function issue(Business $business, string $purpose, array $attrs, int $ttlDays): array
    {
        $ttl = max(config('finance.links.min_ttl_days'), min(config('finance.links.max_ttl_days'), $ttlDays));
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = PublicToken::create(array_merge($attrs, [
            'business_id' => $business->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays($ttl),
            'created_by' => app(Context::class)->user?->id,
        ]));

        return [$token, $raw];
    }

    public function find(string $raw, string $purpose): ?PublicToken
    {
        if (strlen($raw) < 40 || strlen($raw) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $raw)) {
            return null;
        }
        $t = PublicToken::where('token_hash', hash('sha256', $raw))->where('purpose', $purpose)->first();
        if (! $t || ! $t->isValid()) return null;
        $business = Business::find($t->business_id);
        if (! $business || ! $business->isActive()) return null;

        return $t;
    }

    public function grant(Request $request, PublicToken $token): void
    {
        $grants = $request->session()->get(self::SESSION_KEY, []);
        $grants[$token->id] = $token->expires_at->timestamp;
        $request->session()->put(self::SESSION_KEY, array_slice($grants, -20, null, true));
        $request->session()->regenerate();
        $token->forceFill([
            'first_used_at' => $token->first_used_at ?? now(),
            'last_used_at' => now(),
            'use_count' => $token->use_count + 1,
        ])->save();
    }

    /** Find a valid granted token in this browser session matching a predicate. */
    public function granted(Request $request, string $purpose, callable $match): ?PublicToken
    {
        $grants = $request->session()->get(self::SESSION_KEY, []);
        if (! $grants) return null;
        $tokens = PublicToken::whereIn('id', array_keys($grants))->where('purpose', $purpose)->get();
        foreach ($tokens as $t) {
            if ($t->isValid() && $match($t) && Business::find($t->business_id)?->isActive()) {
                return $t;
            }
        }

        return null;
    }

    public function revokeFor(string $column, int $id): int
    {
        return PublicToken::where($column, $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function looksAutomated(?string $ua): bool
    {
        $ua = strtolower((string) $ua);

        return $ua === '' || (bool) preg_match('/bot|crawler|spider|preview|scanner|safelinks|proofpoint|mimecast|barracuda|curl|wget|python|headless|slurp|facebookexternalhit|googleimageproxy/', $ua);
    }
}
