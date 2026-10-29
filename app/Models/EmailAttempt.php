<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
