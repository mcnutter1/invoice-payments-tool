<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToBusiness;

    protected $table = 'products_services';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function incomeAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'income_account_id'); }
    public function taxRate(): BelongsTo { return $this->belongsTo(TaxRate::class); }
}
