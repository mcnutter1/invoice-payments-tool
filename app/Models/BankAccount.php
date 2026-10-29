<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['opening_date' => 'date', 'active' => 'boolean'];

    public function chartAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'chart_account_id'); }
    public function transactions(): HasMany { return $this->hasMany(BankTransaction::class); }
    public function reconciliations(): HasMany { return $this->hasMany(ReconciliationSession::class); }
}
