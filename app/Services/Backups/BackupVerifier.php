<?php

namespace App\Services\Backups;

use App\Models\BackupSnapshot;
use Throwable;

/** Proves a backup point is restorable: downloads it, checks the checksum, decrypts and rebuilds it. */
class BackupVerifier
{
    public function __construct(private readonly BackupReader $reader) {}

    public function verify(BackupSnapshot $s): bool
    {
        try {
            $state = $this->reader->state($s);
            $rows = array_sum(array_map('count', $state['rows']));
            if ($rows !== (int) $s->row_count) {
                throw new \RuntimeException("Rebuilt {$rows} records but the backup recorded {$s->row_count}.");
            }
            $s->update(['verified_at' => now(), 'verify_error' => null]);

            return true;
        } catch (Throwable $e) {
            $s->update(['verified_at' => now(), 'verify_error' => mb_substr($e->getMessage(), 0, 1000)]);

            return false;
        }
    }

    /** The newest restorable point for a business. */
    public function latest(int $businessId): ?BackupSnapshot
    {
        return BackupSnapshot::where('business_id', $businessId)->where('status', 'completed')->latest('id')->first();
    }
}
