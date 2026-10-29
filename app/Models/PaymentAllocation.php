<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['applied_date' => 'date', 'reversed_at' => 'datetime'];

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
