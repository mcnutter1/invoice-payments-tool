<?php

namespace Tests\Feature;

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
        $this->signed(['action' => 'enroll', 'challenge' => 'x'])->assertStatus(400);
        $this->signed(['action' => 'migrate'])->assertStatus(400);
        $this->signed(['action' => 'migrate', 'confirm' => true])->assertOk()->assertJsonPath('data.migrated', true)->assertJsonPath('data.applied', []);
    }

    public function test_ip_allowlist(): void
    {
        config(['finance.srvmgr.allowed_ips' => '203.0.113.10']);
        $this->signed(['action' => 'health'])->assertStatus(401);
        config(['finance.srvmgr.allowed_ips' => '127.0.0.1, 203.0.113.10']);
        $this->signed(['action' => 'health'])->assertOk();
    }
}
