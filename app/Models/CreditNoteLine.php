<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class CreditNoteLine extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $guarded = ['id'];
}
