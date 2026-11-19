<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxTable extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'year' => 'integer'];
}
