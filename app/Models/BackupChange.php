<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['changed_at' => 'datetime'];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(BackupSnapshot::class, 'snapshot_id');
    }
}
