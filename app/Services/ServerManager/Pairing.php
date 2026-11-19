<?php

namespace App\Services\ServerManager;

use App\Exceptions\DomainException;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Server Manager gated pairing (manage.mcnutt.cloud/integrate): the app mints its own shared secret and
 * Ed25519 identity, verifies manager-signed unlock/claim tokens offline, and releases the secret once.
 */
class Pairing
{
    private const SECRET = 'srvmgr.secret';
    private const SIGNKEY = 'srvmgr.app_signkey';
    private const STATE = 'srvmgr.pairing';
    private const MANAGER_URL = 'srvmgr.manager_url';
    private const MANAGER_PUBKEY = 'srvmgr.manager_pubkey';
    public const TTL = 900;

    /** HMAC key for signed requests; an env token overrides the generated one. */
    public function secret(): string
    {
        $env = (string) config('finance.srvmgr.token');
        if ($env !== '') return $env;
        $s = PlatformSetting::readSecret(self::SECRET);
        if (! $s) {
            $s = bin2hex(random_bytes(32));
            PlatformSetting::writeSecret(self::SECRET, $s);
        }

        return $s;
    }

    public function envSecret(): bool
    {
        return (string) config('finance.srvmgr.token') !== '';
    }

    /** Signed requests are only accepted once a manager holds the secret. */
    public function active(): bool
    {
        return $this->envSecret() || $this->isPaired();
    }

    public function state(): array
    {
        return (array) PlatformSetting::read(self::STATE, []);
    }

    public function isPaired(): bool
    {
        return ! empty($this->state()['paired_at']);
    }

    public function managerUrl(): string
    {
        return rtrim((string) (PlatformSetting::read(self::MANAGER_URL) ?: config('finance.srvmgr.manager_url')), '/');
    }

    public function pinnedPubkey(): ?string
    {
        return (string) config('finance.srvmgr.manager_pubkey') ?: PlatformSetting::read(self::MANAGER_PUBKEY);
    }

    public function saveManager(string $url, ?string $pubkey): void
    {
        $changed = $url !== $this->managerUrl();
        PlatformSetting::write(self::MANAGER_URL, rtrim($url, '/'));
        if ($pubkey !== null && $pubkey !== '') {
            if (strlen(self::b64d($pubkey)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new DomainException('That public key is not a valid Ed25519 key (base64url, 32 bytes).');
            }
            PlatformSetting::write(self::MANAGER_PUBKEY, trim($pubkey));
        } elseif ($changed) {
            PlatformSetting::write(self::MANAGER_PUBKEY, null);
        }
    }

    /** Manager public key; fetched from the manager once and pinned (trust on first use). */
    public function managerPubkey(): string
    {
        if ($pk = $this->pinnedPubkey()) return $pk;
        try {
            $res = Http::timeout(8)->acceptJson()->get($this->managerUrl().'/api/pair/pubkey');
        } catch (\Throwable) {
            throw new DomainException('Could not reach '.$this->managerUrl().' to fetch its public key.');
        }
        $pk = trim((string) ($res->json('data.pubkey') ?? $res->json('pubkey') ?? ''));
        if (! $res->ok() || strlen(self::b64d($pk)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DomainException('Server Manager did not return a valid public key.');
        }
        PlatformSetting::write(self::MANAGER_PUBKEY, $pk);

        return $pk;
    }

    public function appPublicKey(): string
    {
        return self::b64(sodium_crypto_sign_publickey($this->keypair()));
    }

    /** Verify a manager-signed unlock token and mint a single-use enrollment payload. */
    public function unlock(string $token, string $helperUrl, string $host): array
    {
        if ($this->isPaired()) throw new DomainException('Already paired. Reset pairing first to pair again.');
        $doc = self::verify(trim($token), $this->managerPubkey());
        if (! $doc || ($doc['typ'] ?? '') !== 'unlock' || empty($doc['jti'])) {
            throw new DomainException('Invalid or tampered unlock token. Generate a new one in Server Manager → Apps → Pair app.');
        }
        if ((int) ($doc['exp'] ?? 0) < time()) throw new DomainException('This unlock token has expired. Generate a new one in Server Manager.');

        $challenge = implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
        $exp = time() + self::TTL;
        $payload = $this->sign(['v' => 2, 'typ' => 'enroll', 'url' => $helperUrl, 'host' => $host, 'challenge' => $challenge,
            'jti' => (string) $doc['jti'], 'app_pub' => $this->appPublicKey(), 'iat' => time(), 'exp' => $exp]);
        $state = ['challenge' => $challenge, 'expires' => $exp, 'used' => false, 'unlock_jti' => (string) $doc['jti'], 'unlocked_at' => time(), 'payload' => $payload];
        PlatformSetting::write(self::STATE, $state);

        return $state;
    }

    /** The manager's enroll call: hands over the secret exactly once. Returns [httpStatus, data|error]. */
    public function enroll(array $req, ?string $ip, string $host): array
    {
        return DB::transaction(function () use ($req, $ip, $host) {
            PlatformSetting::query()->whereKey(self::STATE)->lockForUpdate()->first();
            $doc = $this->state();
            if (! empty($doc['paired_at'])) return [409, 'already paired'];
            $active = (string) ($doc['challenge'] ?? '');
            $code = trim((string) ($req['challenge'] ?? ''));
            if ($code === '' || $active === '' || ! hash_equals($active, $code)) return [403, 'invalid challenge'];
            if ((int) ($doc['expires'] ?? 0) < time() || ! empty($doc['used'])) return [403, 'challenge expired'];

            $claim = is_string($req['claim'] ?? null) && ($pk = $this->pinnedPubkey()) ? self::verify($req['claim'], $pk) : null;
            $okClaim = is_array($claim) && ($claim['typ'] ?? '') === 'claim'
                && hash_equals((string) $doc['unlock_jti'], (string) ($claim['jti'] ?? ''))
                && hash_equals($active, (string) ($claim['challenge'] ?? ''))
                && (int) ($claim['exp'] ?? 0) >= time();
            if (! $okClaim) return [403, 'a valid manager-signed claim is required'];

            PlatformSetting::write(self::STATE, ['used' => true, 'paired_at' => now()->toIso8601String(), 'paired_ip' => $ip, 'unlock_jti' => $doc['unlock_jti']]);

            return [200, ['secret' => $this->secret(), 'host' => $host, 'paired' => true]];
        });
    }

    /** Forget the pairing and rotate the generated secret so the old manager link stops working. */
    public function reset(): void
    {
        PlatformSetting::write(self::STATE, []);
        PlatformSetting::writeSecret(self::SECRET, bin2hex(random_bytes(32)));
    }

    private function keypair(): string
    {
        $pair = self::b64d((string) PlatformSetting::readSecret(self::SIGNKEY));
        if (strlen($pair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            $pair = sodium_crypto_sign_keypair();
            PlatformSetting::writeSecret(self::SIGNKEY, self::b64($pair));
        }

        return $pair;
    }

    private function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return self::b64($json).'.'.self::b64(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($this->keypair())));
    }

    /** Verify a compact token base64url(json).base64url(sig); returns the payload or null. */
    public static function verify(string $token, string $pubB64): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$json, $sig, $pub] = [self::b64d($parts[0]), self::b64d($parts[1]), self::b64d($pubB64)];
        if ($json === '' || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return null;
        try {
            if (! sodium_crypto_sign_verify_detached($sig, $json, $pub)) return null;
        } catch (\Throwable) {
            return null;
        }
        $doc = json_decode($json, true);

        return is_array($doc) ? $doc : null;
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64d(string $enc): string
    {
        $d = base64_decode(strtr(trim($enc), '-_', '+/'), true);

        return $d === false ? '' : $d;
    }
}
