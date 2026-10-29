<?php

namespace App\Services\Auth;

interface AuthGateway
{
    public function loginUrl(string $returnUrl): string;

    /** @param array<string,mixed> $query raw callback query (payload, sig, app_id) */
    public function verifyCallback(array $query): VerifiedIdentity;

    /** Returns null when the session was revoked/invalid; throws AuthUnavailable on outage. */
    public function revalidate(string $sessionToken): ?VerifiedIdentity;

    public function logoutUrl(string $returnUrl, ?string $sessionToken = null): string;

    /** Central "access denied" page for a rejected sign-in, or null to render a local error. */
    public function accessDeniedUrl(string $reason, string $returnUrl): ?string;

    /** @return array{status:string,message:string} status: disabled|unconfigured|healthy|failed */
    public function health(): array;
}
