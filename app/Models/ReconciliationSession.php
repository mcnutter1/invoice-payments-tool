<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationSession extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['statement_date' => 'date', 'completed_at' => 'datetime'];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
}
