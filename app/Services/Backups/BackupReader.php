<?php

namespace App\Services\Backups;

use App\Models\BackupFile;
use App\Models\BackupSnapshot;
use RuntimeException;

/** Loads snapshots and rebuilds the business as it was at any backup point (full + deltas). */
class BackupReader
{
    private array $payloads = [];

    public function __construct(private readonly BackupSettings $settings, private readonly BackupCatalog $catalog) {}

    public function payload(BackupSnapshot $s): array
    {
        if (isset($this->payloads[$s->id])) return $this->payloads[$s->id];
        if (! $s->isComplete() || ! $s->object_key) throw new RuntimeException('This backup did not complete.');
        $stored = $this->settings->store()->get($s->object_key);
        if (! hash_equals((string) $s->sha256, hash('sha256', $stored))) throw new RuntimeException('Backup checksum mismatch: the stored object was changed or damaged.');

        return $this->payloads[$s->id] = BackupCodec::decodeJson($stored);
    }

    /** Snapshots needed to rebuild $s: its daily full followed by each delta up to and including $s. */
    public function chain(BackupSnapshot $s)
    {
        $baseId = $s->type === 'full' ? $s->id : $s->base_snapshot_id;

        return BackupSnapshot::where('business_id', $s->business_id)->where('status', 'completed')
            ->where(fn ($q) => $q->where('id', $baseId)->orWhere(fn ($q) => $q->where('base_snapshot_id', $baseId)->where('type', 'delta')))
            ->where('id', '<=', $s->id)->orderBy('id')->get();
    }

    /** @return array{rows: array<string, array<string, array>>, users: list<array>} */
    public function state(BackupSnapshot $s): array
    {
        $rows = [];
        $users = [];
        foreach ($this->chain($s) as $i => $snap) {
            $p = $this->payload($snap);
            if ($i === 0 && $snap->type !== 'full') throw new RuntimeException('The full backup this point depends on is no longer available.');
            foreach ($p['rows'] as $t => $list) {
                foreach ($list as $row) $rows[$t][$this->catalog->keyOf($t, $row)] = $row;
            }
            // The key manifest drops rows deleted since the previous point.
            foreach ($p['keys'] as $t => $keys) {
                $rows[$t] = array_intersect_key($rows[$t] ?? [], array_flip($keys));
            }
            $users = $p['users'] ?? $users;
        }

        return ['rows' => $rows, 'users' => $users];
    }

    /** A record exactly as captured in one snapshot (only snapshots where it changed carry it). */
    public function version(BackupSnapshot $s, string $table, string $key): ?array
    {
        foreach ($this->payload($s)['rows'][$table] ?? [] as $row) {
            if ($this->catalog->keyOf($table, $row) === $key) return $row;
        }

        return null;
    }

    /** @return array{bytes:string, mime:?string, name:?string}|null */
    public function file(int $businessId, int $documentId): ?array
    {
        $f = BackupFile::where('business_id', $businessId)->where('document_id', $documentId)->first();
        if (! $f) return null;
        $bytes = BackupCodec::decode($this->settings->store()->get($f->object_key));
        if (! hash_equals($f->sha256, hash('sha256', $bytes))) throw new RuntimeException('Backed-up file checksum mismatch.');

        return ['bytes' => $bytes, 'mime' => $f->mime, 'name' => $f->original_name];
    }
}
