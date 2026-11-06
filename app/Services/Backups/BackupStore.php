<?php

namespace App\Services\Backups;

/** Object storage for backup snapshots and files. */
interface BackupStore
{
    /** @param ?string $storageClass S3 storage class (e.g. GLACIER_IR); null = bucket default */
    public function put(string $key, string $bytes, ?string $storageClass = null): void;

    public function get(string $key): string;

    public function delete(string $key): void;
}
