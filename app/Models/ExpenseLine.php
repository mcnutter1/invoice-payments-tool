<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseLine extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['billable' => 'boolean'];

    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function account(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'account_id'); }
}
