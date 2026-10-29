<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    public function defaultAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'default_account_id'); }
    public function expenses(): HasMany { return $this->hasMany(Expense::class); }
}
