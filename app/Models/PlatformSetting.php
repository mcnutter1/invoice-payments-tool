<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Application-wide key/value settings (managed by SSO admins). */
class PlatformSetting extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? json_decode((string) $row->value, true) : $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function readSecret(string $key): ?string
    {
        $v = static::read($key);

        return $v ? Crypt::decryptString($v) : null;
    }

    public static function writeSecret(string $key, string $value): void
    {
        static::write($key, Crypt::encryptString($value));
    }
}
