<?php

namespace App\Services\Auth;

/**
 * Local-development identity provider. Refused at boot when APP_ENV=production.
 * Callbacks are HMAC-signed with APP_KEY so the same verification path is exercised.
 */
class DevAuthGateway implements AuthGateway
{
    public function loginUrl(string $returnUrl): string
    {
        return url('/auth/dev-login').'?return_url='.rawurlencode($returnUrl);
    }

    public function logoutUrl(string $returnUrl, ?string $sessionToken = null): string
    {
        return $returnUrl;
    }

    public function accessDeniedUrl(string $reason, string $returnUrl): ?string
    {
        return null;
    }

    public static function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $json, (string) config('app.key'), true)), '+/', '-_'), '=');

        return ['payload' => $json, 'sig' => $sig, 'app_id' => 'dev'];
    }

    public function verifyCallback(array $query): VerifiedIdentity
    {
        $json = (string) ($query['payload'] ?? '');
        $expected = self::sign(json_decode($json, true) ?? [])['sig'];
        if (! hash_equals($expected, (string) ($query['sig'] ?? ''))) {
            throw new AuthRejected('bad_signature');
        }
        $p = json_decode($json, true);
        if (($p['exp'] ?? 0) < time()) {
            throw new AuthRejected('expired');
        }
        if (($p['principal']['type'] ?? '') !== 'user') {
            throw new AuthRejected('unsupported_principal');
        }

        return $this->fromPayload($p);
    }

    public function revalidate(string $sessionToken): ?VerifiedIdentity
    {
        $p = json_decode((string) base64_decode($sessionToken), true);

        return is_array($p) ? $this->fromPayload(array_merge($p, ['exp' => time() + 3600])) : null;
    }

    public function health(): array
    {
        return ['status' => 'healthy', 'message' => 'Development identity provider (not for production)'];
    }

    private function fromPayload(array $p): VerifiedIdentity
    {
        $token = base64_encode(json_encode(['principal' => $p['principal'], 'identity' => $p['identity'], 'roles' => $p['roles'] ?? []]));

        return new VerifiedIdentity(
            principalType: 'user',
            principalId: (string) $p['principal']['id'],
            email: strtolower((string) ($p['identity']['email'] ?? '')),
            name: $p['identity']['name'] ?? null,
            username: $p['identity']['username'] ?? null,
            roles: $p['roles'] ?? [],
            sessionToken: $token,
            expiresAt: (int) $p['exp'],
            issuedAt: time(),
        );
    }
}
