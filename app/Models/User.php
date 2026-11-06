<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'central_roles' => 'array',
        'last_login_at' => 'datetime',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function label(): string
    {
        return $this->name ?: ($this->email ?: $this->central_principal_id);
    }

    /** SSO "admin" role or a principal listed in FINANCE_PLATFORM_ADMINS: may manage application-level settings. */
    public function isPlatformAdmin(): bool
    {
        $admins = array_filter(array_map('trim', explode(',', (string) config('finance.platform_admins'))));

        return in_array($this->central_principal_id, $admins, true) || in_array('admin', (array) $this->central_roles, true);
    }
}
