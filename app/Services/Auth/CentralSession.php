<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\Crypt;

/** Local application session bound to a verified central identity. */
class CentralSession
{
    private const KEY = 'central_auth';

    public function __construct(private readonly AuthGateway $gateway) {}

    public function establish(VerifiedIdentity $id): User
    {
        $user = User::firstOrNew([
            'central_principal_type' => $id->principalType,
            'central_principal_id' => $id->principalId,
        ]);
        $user->fill([
            'email' => $id->email,
            'name' => $id->name,
            'username' => $id->username,
            'central_roles' => $id->roles,
            'last_login_at' => now(),
        ])->save();

        session()->invalidate();
        session()->regenerate(true);
        session()->put(self::KEY, [
            'user_id' => $user->id,
            'principal_id' => $id->principalId,
            'token' => $id->sessionToken ? Crypt::encryptString($id->sessionToken) : null,
            'exp' => $id->expiresAt,
            'validated_at' => time(),
        ]);
        app(\App\Support\Context::class)->user = $user;
        Audit::log(null, 'auth.login', $user);

        return $user;
    }

    public function user(): ?User
    {
        $data = session(self::KEY);
        if (! $data) return null;

        return User::find($data['user_id']);
    }

    /**
     * Revalidate if older than $maxAge seconds. Returns false if the session is revoked/invalid.
     * Throws AuthUnavailable during outages (callers fail closed).
     */
    public function ensureFresh(int $maxAge): bool
    {
        $data = session(self::KEY);
        if (! $data) return false;
        if (time() - (int) $data['validated_at'] < $maxAge && (int) $data['exp'] > time()) {
            return true;
        }
        $token = $data['token'] ? Crypt::decryptString($data['token']) : null;
        if (! $token) return false;

        $id = $this->gateway->revalidate($token);
        if (! $id || $id->principalId !== $data['principal_id']) {
            $this->forget();

            return false;
        }
        $user = User::find($data['user_id']);
        if (! $user) {
            $this->forget();

            return false;
        }
        $user->forceFill(['central_roles' => $id->roles, 'email' => $id->email, 'name' => $id->name])->save();
        $data['validated_at'] = time();
        $data['exp'] = $id->expiresAt;
        session()->put(self::KEY, $data);

        return true;
    }

    public function sessionToken(): ?string
    {
        $data = session(self::KEY);

        return ($data['token'] ?? null) ? Crypt::decryptString($data['token']) : null;
    }

    public function validatedAt(): ?int
    {
        return session(self::KEY)['validated_at'] ?? null;
    }

    public function forget(): void
    {
        session()->forget(self::KEY);
        session()->invalidate();
        session()->regenerateToken();
    }
}
