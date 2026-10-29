<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyMovement extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['movement_date' => 'date'];

    public const KINDS = [
        'transfer' => 'Transfer between accounts',
        'owner_contribution' => 'Owner contribution',
        'owner_draw' => 'Owner draw',
        'loan_proceeds' => 'Loan proceeds received',
        'loan_payment' => 'Loan principal payment',
    ];

    public function fromAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'from_account_id'); }
    public function toAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'to_account_id'); }
}
