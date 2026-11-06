<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Backup objects are gzip-compressed and encrypted with the application key before upload. */
class BackupCodec
{
    public static function encode(string $bytes): string
    {
        return Crypt::encryptString(gzencode($bytes, 6));
    }

    public static function decode(string $stored): string
    {
        $raw = gzdecode(Crypt::decryptString($stored));
        if ($raw === false) throw new RuntimeException('Backup object is corrupt.');

        return $raw;
    }

    public static function encodeJson(array $payload): string
    {
        return self::encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    public static function decodeJson(string $stored): array
    {
        return json_decode(self::decode($stored), true, 512, JSON_THROW_ON_ERROR);
    }
}
