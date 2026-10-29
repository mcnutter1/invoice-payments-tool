<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['refund_date' => 'date'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
