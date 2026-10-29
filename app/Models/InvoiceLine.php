<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function incomeAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'income_account_id'); }
}
