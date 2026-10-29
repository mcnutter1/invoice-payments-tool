<?php

namespace App\Services\Auth;

final class VerifiedIdentity
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly string $principalType,
        public readonly string $principalId,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly ?string $username,
        public readonly array $roles,
        public readonly ?string $sessionToken,
        public readonly int $expiresAt,
        public readonly ?int $issuedAt = null,
    ) {}
}
