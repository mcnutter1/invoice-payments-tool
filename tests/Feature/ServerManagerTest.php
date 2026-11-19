<?php

namespace Tests\Feature;

use App\Services\ServerManager\Pairing;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerManagerTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.srvmgr.token' => self::SECRET, 'finance.srvmgr.allow_legacy_token' => false]);
    }

    private function signed(array $body, ?string $nonce = null, ?int $ts = null, string $secret = self::SECRET)
    {
        $raw = json_encode($body);
        $ts ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = implode("\n", [$ts, $nonce, 'POST', '/srvmgr/helper', hash('sha256', $raw)]);
        $sig = 'v1='.rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');

        return $this->call('POST', '/srvmgr/helper', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SRVMGR_TIMESTAMP' => (string) $ts,
            'HTTP_X_SRVMGR_NONCE' => $nonce,
            'HTTP_X_SRVMGR_SIGNATURE' => $sig,
        ], $raw);
    }

    public function test_signed_health_and_stats(): void
    {
        $this->signed(['action' => 'health'])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('data.db', true)
            ->assertJsonStructure(['data' => ['status', 'db', 'time']]);
        $this->signed(['action' => 'stats'])->assertOk()->assertJsonStructure(['data' => ['users', 'businesses', 'invoices']]);
        $this->signed(['action' => 'components'])->assertOk()->assertJsonPath('data.components.0.id', 'database');
        $this->signed(['action' => 'version'])->assertOk()->assertJsonStructure(['data' => ['version']]);
    }

    public function test_rejects_bad_signature_stale_timestamp_replay_and_legacy_token(): void
    {
        $this->signed(['action' => 'health'], secret: 'wrong-secret-wrong-secret-wrong-secret')
            ->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'unauthorized']);
        $this->signed(['action' => 'health'], ts: time() - 301)->assertStatus(401);

        $nonce = bin2hex(random_bytes(16));
        $this->signed(['action' => 'health'], $nonce)->assertOk();
        $this->signed(['action' => 'health'], $nonce)->assertStatus(401);

        $legacy = fn () => $this->postJson('/srvmgr/helper', ['action' => 'health'], ['X-Srvmgr-Token' => self::SECRET]);
        $legacy()->assertStatus(401);
        config(['finance.srvmgr.allow_legacy_token' => true]);
        $legacy()->assertOk();
    }

    public function test_unconfigured_token_rejects_everything(): void
    {
        $this->signed(['action' => 'health'])->assertOk();
        config(['finance.srvmgr.token' => '']);
        $this->signed(['action' => 'health'], secret: '')->assertStatus(401);
    }

    public function test_unknown_action_and_guarded_migrate(): void
    {
        $this->signed(['action' => 'nope'])->assertStatus(400)->assertExactJson(['ok' => false, 'error' => 'unknown action']);
        $this->signed(['action' => 'enroll', 'challenge' => 'x'])->assertStatus(403)->assertJsonPath('error', 'invalid challenge');
        $this->signed(['action' => 'migrate'])->assertStatus(400);
        $this->signed(['action' => 'migrate', 'confirm' => true])->assertOk()->assertJsonPath('data.migrated', true)->assertJsonPath('data.applied', []);
    }

    private function managerToken(string $managerKey, array $payload): string
    {
        $json = json_encode($payload);
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($managerKey)));
    }

    public function test_settings_page_pairs_with_signed_tokens_and_hands_over_generated_secret_once(): void
    {
        config(['finance.srvmgr.token' => '']);
        $manager = sodium_crypto_sign_keypair();
        $otherManager = sodium_crypto_sign_keypair();
        $pub = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($manager)), '+/', '-_'), '=');
        Http::fake(['manage.mcnutt.cloud/api/pair/pubkey' => Http::response(['ok' => true, 'data' => ['pubkey' => $pub, 'alg' => 'ed25519']])]);
        $admin = $this->makeUser('u_root', null, ['admin', 'user']);

        $this->signIn($this->makeUser('u_plain'))->get('/platform/server-manager')->assertForbidden();
        $this->signIn($admin)->get('/platform/server-manager')->assertOk()->assertSee('Not paired');
        // Nothing is accepted before pairing, even when signed with the generated secret.
        $this->signed(['action' => 'health'], secret: app(Pairing::class)->secret())->assertStatus(401);

        $forged = $this->managerToken($otherManager, ['typ' => 'unlock', 'jti' => 'j1', 'exp' => time() + 600]);
        $this->signIn($admin)->post('/platform/server-manager/unlock', ['unlock_token' => $forged])->assertSessionHas('error');
        $expired = $this->managerToken($manager, ['typ' => 'unlock', 'jti' => 'j1', 'exp' => time() - 1]);
        $this->signIn($admin)->post('/platform/server-manager/unlock', ['unlock_token' => $expired])->assertSessionHas('error');

        $unlock = $this->managerToken($manager, ['typ' => 'unlock', 'jti' => 'jti-123', 'exp' => time() + 600]);
        $this->signIn($admin)->post('/platform/server-manager/unlock', ['unlock_token' => $unlock])->assertSessionHas('status');
        $state = app(Pairing::class)->state();
        $this->signIn($admin)->get('/platform/server-manager')->assertOk()->assertSee($state['payload']);

        // The enrollment payload is signed by the app and bound to the manager's jti.
        $appPub = json_decode(Pairing::b64d(explode('.', $state['payload'])[0]), true)['app_pub'];
        $payload = Pairing::verify($state['payload'], $appPub);
        $this->assertSame(['enroll', 'jti-123', $state['challenge'], url('/srvmgr/helper')], [$payload['typ'], $payload['jti'], $payload['challenge'], $payload['url']]);

        $enroll = fn (array $extra) => $this->postJson('/srvmgr/helper', ['action' => 'enroll', 'challenge' => $state['challenge'], 'jti' => 'jti-123'] + $extra);
        $enroll([])->assertStatus(403);
        $enroll(['claim' => $this->managerToken($manager, ['typ' => 'claim', 'jti' => 'other', 'challenge' => $state['challenge'], 'exp' => time() + 60])])->assertStatus(403);
        $enroll(['claim' => $this->managerToken($otherManager, ['typ' => 'claim', 'jti' => 'jti-123', 'challenge' => $state['challenge'], 'exp' => time() + 60])])->assertStatus(403);
        $claim = $this->managerToken($manager, ['typ' => 'claim', 'jti' => 'jti-123', 'challenge' => $state['challenge'], 'exp' => time() + 60]);
        $secret = $enroll(['claim' => $claim])->assertOk()->assertJsonPath('data.paired', true)->json('data.secret');
        $this->assertSame(64, strlen($secret));
        $enroll(['claim' => $claim])->assertStatus(409);

        $this->signed(['action' => 'health'], secret: $secret)->assertOk();
        $this->signIn($admin)->get('/platform/server-manager')->assertOk()->assertSee('Connected');

        $this->signIn($admin)->post('/platform/server-manager/reset')->assertSessionHas('status');
        $this->signed(['action' => 'health'], secret: $secret)->assertStatus(401);
        $this->assertNotSame($secret, app(Pairing::class)->secret());
    }

    public function test_ip_allowlist(): void
    {
        config(['finance.srvmgr.allowed_ips' => '203.0.113.10']);
        $this->signed(['action' => 'health'])->assertStatus(401);
        config(['finance.srvmgr.allowed_ips' => '127.0.0.1, 203.0.113.10']);
        $this->signed(['action' => 'health'])->assertOk();
    }
}
