<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ChartAccount extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Debit-normal accounts carry positive balances as debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
