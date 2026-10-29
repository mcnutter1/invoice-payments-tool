<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class DocumentAccessEvent extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['probable_automation' => 'boolean', 'created_at' => 'datetime'];
}
