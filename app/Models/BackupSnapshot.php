<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BackupSnapshot extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = [
        'since_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'table_stats' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (BackupSnapshot $s) => $s->ulid ??= strtolower((string) Str::ulid()));
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function changeCount(): int
    {
        return $this->created_count + $this->updated_count + $this->deleted_count;
    }

    public static function humanBytes(int|float|null $n): string
    {
        $n = (float) $n;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($n < 1024 || $u === 'TB') return ($u === 'B' ? (int) $n : number_format($n, 1)).' '.$u;
            $n /= 1024;
        }

        return '';
    }
}
