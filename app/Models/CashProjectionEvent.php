<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashProjectionEvent extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $table = 'cash_basis_projection_events';

    protected $guarded = ['id'];

    protected $casts = ['event_date' => 'date'];

    public function account(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'account_id'); }
}
