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
}
