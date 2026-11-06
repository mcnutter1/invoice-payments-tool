<?php

namespace Tests\Support;

use App\Services\Backups\BackupStore;
use RuntimeException;

class MemoryBackupStore implements BackupStore
{
    /** @var array<string,string> */
    public array $objects = [];

    /** @var array<string,?string> */
    public array $classes = [];

    public int $reads = 0;

    public function put(string $key, string $bytes, ?string $storageClass = null): void
    {
        $this->objects[$key] = $bytes;
        $this->classes[$key] = $storageClass;
    }

    public function get(string $key): string
    {
        $this->reads++;

        return $this->objects[$key] ?? throw new RuntimeException('missing '.$key);
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
