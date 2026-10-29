<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;

/** Request-scoped resolved actor and tenant context. */
class Context
{
    public ?User $user = null;

    public ?Business $business = null;

    public ?Membership $membership = null;

    /** @var list<string> */
    public array $permissions = [];

    public array $roleNames = [];

    public string $actorType = 'user';

    public string $correlationId;

    public function __construct()
    {
        $this->correlationId = (string) \Illuminate\Support\Str::uuid();
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->can($p)) return true;
        }

        return false;
    }
}
