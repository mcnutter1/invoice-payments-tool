<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrivateDocument extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (PrivateDocument $d) => $d->ulid ??= strtolower((string) Str::ulid()));
    }

    public function absolutePath(): string
    {
        return rtrim(config('finance.storage.private_root'), '/').'/'.$this->stored_path;
    }

    public function contents(): string
    {
        return (string) file_get_contents($this->absolutePath());
    }
}
