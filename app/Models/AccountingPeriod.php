<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['closed_through' => 'date'];

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
