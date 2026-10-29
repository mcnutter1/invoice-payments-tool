<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['is_primary_billing' => 'boolean', 'is_signer' => 'boolean'];
}
