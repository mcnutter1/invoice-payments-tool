<?php

namespace App\Services\Backups;

use App\Models\BackupChange;
use App\Models\BackupFile;
use App\Models\BackupRecord;
use App\Models\BackupSnapshot;
use App\Models\Business;
use App\Models\PrivateDocument;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Takes a snapshot of one business: a daily full, otherwise an hourly delta of rows changed since the last point. */
class BackupRunner
{
    /** Re-read a little before the previous run so rows committed while it ran are not missed. */
    private const OVERLAP_SECONDS = 120;

    public function __construct(
        private readonly BackupSettings $settings,
        private readonly BackupCatalog $catalog,
        private readonly BackupReader $reader,
        private readonly BackupSearchIndex $search,
    ) {}

    public function nextType(Business $b): string
    {
        $lastFull = BackupSnapshot::where('business_id', $b->id)->where('type', 'full')->where('status', 'completed')->latest('id')->first();

        return ! $lastFull || $lastFull->started_at->lt(now()->startOfDay()) ? 'full' : 'delta';
    }

    public function run(Business $b, string $trigger = 'schedule', ?string $type = null): BackupSnapshot
    {
        $type ??= $this->nextType($b);
        $last = BackupSnapshot::where('business_id', $b->id)->where('status', 'completed')->latest('id')->first();
        $lastFull = BackupSnapshot::where('business_id', $b->id)->where('type', 'full')->where('status', 'completed')->latest('id')->first();
        if (! $lastFull) $type = 'full';
        $snap = BackupSnapshot::create([
            'business_id' => $b->id, 'type' => $type, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now(),
            'parent_snapshot_id' => $last?->id, 'base_snapshot_id' => $type === 'delta' ? $lastFull->id : null,
            'since_at' => $last?->started_at->copy()->subSeconds(self::OVERLAP_SECONDS),
        ]);
        if ($type === 'full') $snap->update(['base_snapshot_id' => $snap->id]);

        try {
            $this->capture($b, $snap, $last);
        } catch (Throwable $e) {
            report($e);
            $snap->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return $snap->fresh();
    }

    private function capture(Business $b, BackupSnapshot $snap, ?BackupSnapshot $last): void
    {
        $prevKeys = $last ? (self::decodeManifest($last->manifest) ?? ($this->reader->payload($last)['keys'] ?? null)) : null;
        $prevChanged = $last ? BackupChange::where('snapshot_id', $last->id)->get(['table_name', 'record_key'])
            ->mapWithKeys(fn ($c) => [$c->table_name.'|'.$c->record_key => true])->all() : [];
        $rows = $keys = $changes = $stats = $hashes = $texts = [];
        $maps = $this->search->maps($b->id);
        $rowCount = 0;

        foreach (BackupCatalog::TABLES as $t) {
            if (! $this->catalog->exists($t)) continue;
            $meta = $this->catalog->meta($t);
            $all = $snap->type === 'full' || ! $snap->since_at ? $this->catalog->query($t, $b->id)->get()->map(fn ($r) => (array) $r) : null;
            $changed = $snap->since_at ? $this->catalog->changedQuery($t, $b->id, $snap->since_at)->get()->map(fn ($r) => (array) $r) : $all;
            $keys[$t] = $all
                ? $all->map(fn ($r) => $this->catalog->keyOf($t, $r))->all()
                : $this->catalog->query($t, $b->id)->select($meta['key'])->get()->map(fn ($r) => $this->catalog->keyOf($t, (array) $r))->all();
            $rows[$t] = ($all ?? $changed)->values()->all();
            $rowCount += count($keys[$t]);
            $stats[$t] = ['rows' => count($keys[$t]), 'changed' => 0];

            if (! isset(BackupCatalog::ENTITIES[$t])) continue;
            $prev = $prevKeys !== null ? array_flip($prevKeys[$t] ?? []) : null;
            $knownHashes = BackupRecord::where('business_id', $b->id)->where('table_name', $t)->whereNotNull('row_hash')->pluck('row_hash', 'record_key');
            foreach ($changed as $r) {
                $k = $this->catalog->keyOf($t, $r);
                $isNew = $prev === null || ! isset($prev[$k]);
                $hash = BackupCatalog::contentHash($r);
                // Saved without any real change (only timestamps moved): not a new version.
                if (! $isNew && ($knownHashes[$k] ?? null) === $hash) continue;
                $at = $meta['ts'] ? ($r[$meta['ts']] ?? null) : null;
                // Unchanged rows re-read in the overlap window were already recorded by the previous point.
                if (! $isNew && isset($prevChanged[$t.'|'.$k]) && $at && $at <= $last->started_at->format('Y-m-d H:i:s')) continue;
                $changes[] = ['table_name' => $t, 'record_key' => $k, 'action' => $isNew ? 'created' : 'updated', 'label' => $this->catalog->label($t, $r), 'changed_at' => $at ?? now()];
                $hashes[$t.'|'.$k] = $hash;
                $texts[$t.'|'.$k] = $this->search->text($t, $r, $maps);
                $stats[$t]['changed']++;
            }
            if ($prev !== null) {
                $gone = array_diff(array_keys($prev), $keys[$t]);
                $labels = $gone ? BackupRecord::where('business_id', $b->id)->where('table_name', $t)->whereIn('record_key', $gone)->pluck('label', 'record_key') : collect();
                foreach ($gone as $k) {
                    $changes[] = ['table_name' => $t, 'record_key' => (string) $k, 'action' => 'deleted', 'label' => $labels[$k] ?? '#'.$k, 'changed_at' => now()];
                    $stats[$t]['changed']++;
                }
            }
        }

        $userIds = DB::table('memberships')->where('business_id', $b->id)->pluck('user_id');
        $payload = [
            'format' => 1, 'type' => $snap->type, 'snapshot' => $snap->ulid, 'created_at' => $snap->started_at->toIso8601String(),
            'since' => $snap->since_at?->toIso8601String(), 'business' => ['id' => $b->id, 'ulid' => $b->ulid, 'name' => $b->name()],
            'rows' => $rows, 'keys' => $keys,
            'users' => DB::table('users')->whereIn('id', $userIds)->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $store = $this->settings->store();
        $cfg = $this->settings->all();
        $files = $this->uploadFiles($b, $store, $cfg['class_files']);
        $bytes = BackupCodec::encodeJson($payload);
        $class = $snap->type === 'full' ? $cfg['class_full'] : $cfg['class_delta'];
        $objectKey = $this->settings->prefix().'/businesses/'.$b->ulid.'/snapshots/'.$snap->started_at->format('Y/m/d').'/'.$snap->ulid.'-'.$snap->type.'.bin';
        $store->put($objectKey, $bytes, $class);

        DB::transaction(function () use ($b, $snap, $changes, $objectKey, $bytes, $rowCount, $stats, $files, $class, $keys, $hashes, $texts) {
            foreach (array_chunk($changes, 500) as $chunk) {
                BackupChange::insert(array_map(fn ($c) => $c + ['snapshot_id' => $snap->id, 'business_id' => $b->id], $chunk));
            }
            foreach ($changes as $c) {
                $rec = BackupRecord::firstOrNew(['business_id' => $b->id, 'table_name' => $c['table_name'], 'record_key' => $c['record_key']]);
                $rec->fill(['label' => $c['label'], 'last_snapshot_id' => $snap->id, 'last_changed_at' => $c['changed_at'], 'deleted' => $c['action'] === 'deleted',
                    'row_hash' => $hashes[$c['table_name'].'|'.$c['record_key']] ?? null]);
                if (isset($texts[$c['table_name'].'|'.$c['record_key']])) $rec->search_text = $texts[$c['table_name'].'|'.$c['record_key']];
                $rec->versions = ($rec->versions ?? 0) + 1;
                $rec->save();
            }
            $count = fn ($a) => count(array_filter($changes, fn ($c) => $c['action'] === $a));
            $snap->update([
                'status' => 'completed', 'finished_at' => now(), 'object_key' => $objectKey, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
                'row_count' => $rowCount, 'created_count' => $count('created'), 'updated_count' => $count('updated'), 'deleted_count' => $count('deleted'),
                'files_uploaded' => $files, 'table_stats' => array_filter($stats, fn ($s) => $s['rows'] || $s['changed']),
                'storage_class' => $class, 'manifest' => base64_encode(gzencode(json_encode($keys), 6)),
            ]);
            BackupSnapshot::where('business_id', $b->id)->where('id', '!=', $snap->id)->whereNotNull('manifest')->update(['manifest' => null]);
        });
    }

    public static function decodeManifest(?string $m): ?array
    {
        if (! $m) return null;
        $raw = gzdecode((string) base64_decode($m, true));

        return $raw === false ? null : json_decode($raw, true);
    }

    /** Uploads each private document once (content-addressed), so deltas only carry new files. */
    private function uploadFiles(Business $b, BackupStore $store, string $class): int
    {
        $done = BackupFile::where('business_id', $b->id)->pluck('document_id')->all();
        $uploaded = 0;
        PrivateDocument::where('business_id', $b->id)->whereNotIn('id', $done)->orderBy('id')->each(function (PrivateDocument $d) use ($b, $store, $class, &$uploaded) {
            $path = $d->absolutePath();
            if (! is_file($path)) return;
            $bytes = (string) file_get_contents($path);
            $sha = hash('sha256', $bytes);
            $existing = BackupFile::where('business_id', $b->id)->where('sha256', $sha)->value('object_key');
            $key = $existing ?? $this->settings->prefix().'/businesses/'.$b->ulid.'/files/'.$sha.'.bin';
            if (! $existing) {
                $store->put($key, BackupCodec::encode($bytes), $class);
                $uploaded++;
            }
            BackupFile::create(['business_id' => $b->id, 'document_id' => $d->id, 'sha256' => $sha, 'mime' => $d->mime,
                'original_name' => $d->original_name, 'size' => strlen($bytes), 'object_key' => $key, 'storage_class' => $class]);
        });

        return $uploaded;
    }

    /** Drops deltas older than the delta window and fulls (with their chains) older than the full window. */
    public function prune(Business $b): int
    {
        $c = $this->settings->all();
        $store = $this->settings->store();
        $doomed = BackupSnapshot::where('business_id', $b->id)->where(function ($q) use ($c) {
            $q->where(fn ($q) => $q->where('type', 'full')->where('started_at', '<', now()->subDays((int) $c['full_retention_days'])))
                ->orWhere(fn ($q) => $q->where('type', 'delta')->whereIn('base_snapshot_id',
                    BackupSnapshot::where('type', 'full')->where('started_at', '<', now()->subDays((int) $c['delta_retention_days']))->select('id')));
        })->get();
        $fullIds = $doomed->where('type', 'full')->pluck('id');
        $doomed = $doomed->merge(BackupSnapshot::whereIn('base_snapshot_id', $fullIds)->where('type', 'delta')->get())->unique('id');
        foreach ($doomed as $s) {
            if ($s->object_key) {
                try {
                    $store->delete($s->object_key);
                } catch (Throwable $e) {
                    report($e);
                    continue;
                }
            }
            $s->delete();
        }

        return $doomed->count();
    }
}
