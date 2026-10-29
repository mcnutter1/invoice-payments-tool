<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for the McNutt Cloud signed-callback protocol, matching the official PHP client helper
 * (auth.php / config.php samples). See docs/AUTH_INTEGRATION.md.
 */
class McNuttAuthGateway implements AuthGateway
{
    public function __construct(private readonly array $config) {}

    public function loginUrl(string $returnUrl): string
    {
        return strtr($this->config['login_url_template'], [
            '{login_base}' => $this->config['login_base'],
            '{app_id}' => rawurlencode((string) $this->config['app_id']),
            '{return_url}' => rawurlencode($returnUrl),
        ]);
    }

    /** Central logout confirmation UI performs the revocation, so the token must still be valid here. */
    public function logoutUrl(string $returnUrl, ?string $sessionToken = null): string
    {
        if (! $sessionToken) {
            return $returnUrl;
        }

        return strtr($this->config['logout_url_template'], [
            '{login_base}' => $this->config['login_base'],
            '{app_id}' => rawurlencode((string) $this->config['app_id']),
            '{token}' => rawurlencode($sessionToken),
            '{return_url}' => rawurlencode($returnUrl),
        ]);
    }

    public function accessDeniedUrl(string $reason, string $returnUrl): ?string
    {
        $map = ['bad_signature' => 'invalid_signature', 'expired' => 'expired', 'not_authorized' => 'not_authorized'];
        if (! isset($map[$reason])) {
            return null;
        }

        return $this->config['login_base'].'/access_denied.php?'.http_build_query([
            'reason' => $map[$reason],
            'app_id' => $this->config['app_id'],
            'return_url' => $returnUrl,
        ]);
    }

    public function verifyCallback(array $query): VerifiedIdentity
    {
        $this->assertConfigured();
        $raw = $query['payload'] ?? null;
        $sig = $query['sig'] ?? null;
        $appId = $query['app_id'] ?? null;
        if (! is_string($raw) || ! is_string($sig) || ! is_string($appId) || $raw === '' || $sig === '') {
            throw new AuthRejected('missing_parameters');
        }
        if (! hash_equals((string) $this->config['app_id'], $appId)) {
            throw new AuthRejected('wrong_app');
        }

        $payload = $this->verifySignedPayload($raw, $sig);
        $identity = $this->identityFromPayload($payload);

        $maxAge = (int) $this->config['callback_max_age_seconds'];
        if ($identity->issuedAt !== null && $identity->issuedAt < time() - $maxAge) {
            throw new AuthRejected('callback_too_old');
        }

        // Replay protection: each signed callback may be redeemed once while it is valid.
        $sigHash = hash('sha256', $sig);
        DB::table('auth_callback_nonces')->where('expires_at', '<', now())->delete();
        try {
            DB::table('auth_callback_nonces')->insert([
                'sig_hash' => $sigHash,
                'expires_at' => now()->addSeconds(max($maxAge, $identity->expiresAt - time())),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            throw new AuthRejected('callback_replayed');
        }

        if ($this->config['validate_on_callback']) {
            if (! $identity->sessionToken) {
                throw new AuthRejected('missing_session_token');
            }
            // Authenticated confirmation of app access and session liveness.
            $fresh = $this->revalidate($identity->sessionToken);
            if (! $fresh) {
                throw new AuthRejected('not_authorized');
            }
            if ($fresh->principalId !== $identity->principalId || $fresh->principalType !== $identity->principalType) {
                throw new AuthRejected('validation_mismatch');
            }

            return $fresh;
        }

        return $identity;
    }

    public function revalidate(string $sessionToken): ?VerifiedIdentity
    {
        $this->assertConfigured();
        try {
            $res = Http::timeout($this->config['http_timeout'])->acceptJson()->get($this->config['validate_endpoint'], [
                'token' => $sessionToken,
                'app_id' => $this->config['app_id'],
                'client_ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('auth.validate.unreachable', ['error' => class_basename($e)]);
            throw new AuthUnavailable('auth_unreachable');
        }

        if ($res->status() === 429 || $res->serverError()) {
            throw new AuthUnavailable('auth_status_'.$res->status());
        }
        $json = $res->json();
        if (! is_array($json)) {
            throw new AuthUnavailable('auth_bad_response');
        }
        if (($json['ok'] ?? false) !== true) {
            return null;
        }
        if (! isset($json['payload']) || ! is_array($json['payload']) || ! is_string($json['sig'] ?? null)) {
            throw new AuthUnavailable('auth_bad_response');
        }
        // Client helper: HMAC over json_encode(payload, JSON_UNESCAPED_SLASHES).
        if (! $this->signatureMatches((string) json_encode($json['payload'], JSON_UNESCAPED_SLASHES), $json['sig'])) {
            Log::warning('auth.validate.bad_signature');

            return null;
        }

        return $this->identityFromPayload($json['payload']);
    }

    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'unconfigured', 'message' => 'AUTH_APP_ID / AUTH_APP_SECRET not set'];
        }
        try {
            $res = Http::timeout(5)->get($this->config['validate_endpoint'], ['app_id' => $this->config['app_id']]);

            return $res->status() < 500
                ? ['status' => 'healthy', 'message' => 'Validate endpoint reachable (HTTP '.$res->status().')']
                : ['status' => 'failed', 'message' => 'Validate endpoint HTTP '.$res->status()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'Validate endpoint unreachable'];
        }
    }

    /** Client helper: the callback signs the raw `payload` query value (JSON) exactly as received. */
    private function verifySignedPayload(string $raw, string $sig): array
    {
        if (! $this->signatureMatches($raw, $sig)) {
            throw new AuthRejected('bad_signature');
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new AuthRejected('malformed_payload');
        }

        return $payload;
    }

    private function signatureMatches(string $data, string $sig): bool
    {
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $data, (string) $this->config['app_secret'], true)), '+/', '-_'), '=');

        return hash_equals($expected, rtrim($sig, '='));
    }

    /** Only fields INSIDE the signed payload are trusted. Outer/unsigned roles are ignored. */
    private function identityFromPayload(array $p): VerifiedIdentity
    {
        // The helper payload carries identity, roles, session_token, exp; iss/principal/iat are checked when present.
        $issuer = $this->config['expected_issuer'] ?? null;
        if ($issuer && isset($p['iss']) && rtrim((string) $p['iss'], '/') !== rtrim($issuer, '/')) {
            throw new AuthRejected('wrong_issuer');
        }
        $exp = (int) ($p['exp'] ?? 0);
        if ($exp <= time() - 30) {
            throw new AuthRejected('expired');
        }
        $identity = is_array($p['identity'] ?? null) ? $p['identity'] : [];
        $principal = is_array($p['principal'] ?? null) ? $p['principal'] : ['type' => 'user', 'id' => $identity['uid'] ?? ''];
        $type = (string) ($principal['type'] ?? '');
        $pid = (string) ($principal['id'] ?? '');
        if ($pid === '' || ! in_array($type, $this->config['allowed_principal_types'], true)) {
            throw new AuthRejected('unsupported_principal');
        }
        if ($type === 'user' && (string) ($identity['uid'] ?? '') !== $pid) {
            throw new AuthRejected('principal_mismatch');
        }
        $roles = array_values(array_filter((array) ($p['roles'] ?? []), 'is_string'));

        return new VerifiedIdentity(
            principalType: $type,
            principalId: $pid,
            email: isset($identity['email']) ? strtolower((string) $identity['email']) : null,
            name: $identity['name'] ?? null,
            username: $identity['username'] ?? null,
            roles: $roles,
            sessionToken: isset($p['session_token']) ? (string) $p['session_token'] : null,
            expiresAt: $exp,
            issuedAt: isset($p['iat']) ? (int) $p['iat'] : null,
        );
    }

    private function isConfigured(): bool
    {
        return ! empty($this->config['app_id']) && ! empty($this->config['app_secret']);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new AuthUnavailable('auth_unconfigured');
        }
    }
}
