<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['last_changed_at' => 'datetime', 'deleted' => 'boolean'];
}
