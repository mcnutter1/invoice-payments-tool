<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['mapping' => 'array', 'undone_at' => 'datetime'];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function transactions(): HasMany { return $this->hasMany(BankTransaction::class); }
}
