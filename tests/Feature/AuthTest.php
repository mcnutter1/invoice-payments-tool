<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Exercises the McNutt Cloud adapter with fixtures matching the official client helper (auth.php). */
class AuthTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789';

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'iss' => 'https://login.mcnutt.cloud', 'iat' => time(), 'exp' => time() + 3600, 'session_token' => 'sess_abc',
            'principal' => ['type' => 'user', 'id' => 'u_z73h'],
            'identity' => ['uid' => 'u_z73h', 'email' => 'robin@example.test', 'name' => 'Robin', 'username' => 'robin'],
            'roles' => ['user'],
        ], $over);
    }

    private function sign(string $json, string $secret = self::SECRET): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $json, $secret, true)), '+/', '-_'), '=');
    }

    private function fakeValidate(?array $payload = null, int $status = 200): void
    {
        Http::fake(['login.mcnutt.cloud/api/validate.php*' => function () use ($payload, $status) {
            if ($payload === null) return Http::response(['ok' => false, 'reason' => 'revoked'], $status);
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

            return Http::response(['ok' => true, 'payload' => $payload, 'sig' => $this->sign($json), 'roles' => ['admin']], $status);
        }]);
    }

    public function test_valid_callback_creates_session_keyed_by_principal(): void
    {
        $p = $this->payload();
        $this->fakeValidate($p);
        $json = json_encode($p, JSON_UNESCAPED_SLASHES);
        $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $this->sign($json), 'app_id' => 'finance-test']))->assertRedirect('/');
        $u = User::where('central_principal_id', 'u_z73h')->firstOrFail();
        // Outer, unsigned "roles" in the validate response are ignored.
        $this->assertSame(['user'], $u->central_roles);
    }

    public function test_rejections(): void
    {
        $this->fakeValidate($this->payload());
        $denied = 'https://login.mcnutt.cloud/access_denied.php?';
        $cases = [
            'bad hmac' => [$this->payload(), 'wrong-secret', 'finance-test', 'invalid_signature'],
            'expired' => [$this->payload(['exp' => time() - 3600]), self::SECRET, 'finance-test', 'expired'],
            'wrong app' => [$this->payload(), self::SECRET, 'other-app', null],
            'magic principal' => [$this->payload(['principal' => ['type' => 'magic']]), self::SECRET, 'finance-test', null],
            'system api' => [$this->payload(['principal' => ['type' => 'system_api']]), self::SECRET, 'finance-test', null],
            'wrong issuer' => [$this->payload(['iss' => 'https://evil.example']), self::SECRET, 'finance-test', null],
        ];
        foreach ($cases as $name => [$p, $secret, $app, $reason]) {
            $json = json_encode($p, JSON_UNESCAPED_SLASHES);
            $res = $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $this->sign($json, $secret), 'app_id' => $app]));
            if ($reason) {
                $res->assertRedirect($denied.http_build_query(['reason' => $reason, 'app_id' => 'finance-test', 'return_url' => url('/')]));
            } else {
                $res->assertForbidden();
            }
        }
        $this->assertSame(0, User::count());
    }

    public function test_helper_payload_shape_and_not_authorized_app(): void
    {
        // Exactly what the official helper reads: identity, roles, session_token, exp.
        $p = ['identity' => ['uid' => 'u_h1', 'email' => 'Helper@Example.test', 'name' => 'Helper'], 'roles' => ['user'], 'session_token' => 'sess_h', 'exp' => time() + 600];
        $json = json_encode($p, JSON_UNESCAPED_SLASHES);
        $assigned = false;
        Http::fake(['login.mcnutt.cloud/api/validate.php*' => function () use (&$assigned, &$p) {
            return $assigned
                ? Http::response(['ok' => true, 'payload' => $p, 'sig' => $this->sign(json_encode($p, JSON_UNESCAPED_SLASHES))])
                : Http::response(['ok' => false, 'reason' => 'not_assigned']);
        }]);
        $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $this->sign($json), 'app_id' => 'finance-test']))
            ->assertRedirectContains('access_denied.php?reason=not_authorized');
        $this->assertSame(0, User::count());

        $p['exp'] = time() + 601;
        $json = json_encode($p, JSON_UNESCAPED_SLASHES);
        $assigned = true;
        $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $this->sign($json), 'app_id' => 'finance-test']))->assertRedirect('/');
        $this->assertSame('helper@example.test', User::where('central_principal_id', 'u_h1')->value('email'));

        $this->post('/auth/logout')
            ->assertRedirect('https://login.mcnutt.cloud/logout_confirm.php?token=sess_h&return_url='.rawurlencode(url('/')));
        $this->get('/')->assertRedirect();
    }

    public function test_unsigned_role_tampering_and_replay_rejected(): void
    {
        $p = $this->payload();
        $this->fakeValidate($p);
        $json = json_encode($p, JSON_UNESCAPED_SLASHES);
        $sig = $this->sign($json);
        $tampered = json_encode($this->payload(['roles' => ['user', 'admin']]), JSON_UNESCAPED_SLASHES);
        $this->get('/auth/callback?'.http_build_query(['payload' => $tampered, 'sig' => $sig, 'app_id' => 'finance-test']))
            ->assertRedirectContains('reason=invalid_signature');
        $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $sig, 'app_id' => 'finance-test']))->assertRedirect();
        $this->flushSession();
        $this->get('/auth/callback?'.http_build_query(['payload' => $json, 'sig' => $sig, 'app_id' => 'finance-test']))->assertForbidden();
    }

    public function test_revalidation_window_fails_closed_and_revocation_logs_out(): void
    {
        $owner = $this->makeUser('u_z73h');
        $biz = $this->makeBusiness($owner);
        $stale = ['user_id' => $owner->id, 'principal_id' => 'u_z73h', 'token' => \Illuminate\Support\Facades\Crypt::encryptString('sess'), 'exp' => time() + 3600, 'validated_at' => time() - 600];

        $mode = 'down';
        $payload = $this->payload();
        Http::fake(['login.mcnutt.cloud/*' => function () use (&$mode, $payload) {
            if ($mode === 'down') return Http::response('', 503);
            if ($mode === 'revoked') return Http::response(['ok' => false, 'reason' => 'revoked']);
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

            return Http::response(['ok' => true, 'payload' => $payload, 'sig' => $this->sign($json)]);
        }]);
        $this->withSession(['central_auth' => $stale])->get("/b/{$biz->ulid}")->assertStatus(503);

        $mode = 'revoked';
        $this->withSession(['central_auth' => $stale])->get("/b/{$biz->ulid}")->assertRedirect('/auth/login');

        $mode = 'ok';
        $this->withSession(['central_auth' => $stale])->get("/b/{$biz->ulid}")->assertOk();
    }
}
