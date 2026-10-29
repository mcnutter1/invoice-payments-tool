<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPayment extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['payment_date' => 'date'];

    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function account(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'account_id'); }
}
