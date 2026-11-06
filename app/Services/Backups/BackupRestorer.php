<?php

namespace App\Services\Backups;

use App\Exceptions\DomainException;
use App\Models\BackupSnapshot;
use App\Models\Business;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Puts backed-up data back: single records (overwrite / only-if-missing / as a copy) or a whole business. */
class BackupRestorer
{
    public const MODES = [
        'overwrite' => 'Overwrite the current version',
        'missing' => 'Restore only if it no longer exists',
        'copy' => 'Restore as a new copy (keep the current one)',
    ];

    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupReader $reader,
        private readonly BackupRunner $runner,
    ) {}

    /** @return array{table:string, key:string, children:int, files:int, new_key:?string} */
    public function restoreRecord(BackupSnapshot $s, string $table, string $key, string $mode, bool $withChildren = true): array
    {
        if (! in_array($table, BackupCatalog::TABLES, true) || $table === 'businesses' && $mode !== 'overwrite') throw new DomainException('This kind of record can’t be restored that way.');
        if ($mode === 'copy' && ! in_array($table, BackupCatalog::COPYABLE, true)) throw new DomainException('Restoring as a copy isn’t available for '.BackupCatalog::title($table).'.');
        $state = $this->reader->state($s);
        $row = $state['rows'][$table][$key] ?? throw new DomainException('That record isn’t in this backup point.');
        $bid = $s->business_id;
        $where = $this->catalog->keyWhere($table, $key);
        $exists = $this->catalog->query($table, $bid)->where($where)->exists();
        if ($mode === 'missing' && $exists) throw new DomainException('That record still exists. Choose “Overwrite” or “Restore as a copy”.');

        $childTables = $withChildren ? $this->catalog->children($table) : [];
        $children = [];
        foreach ($childTables as $ct) {
            $col = $this->catalog->meta($ct)['parent'][1];
            $children[$ct] = array_values(array_filter($state['rows'][$ct] ?? [], fn ($r) => (string) ($r[$col] ?? '') === (string) ($row['id'] ?? '')));
        }

        $result = DB::transaction(function () use ($table, $row, $where, $mode, $children, $bid) {
            $this->deferForeignKeys();
            if ($mode === 'copy') {
                $newId = DB::table($table)->insertGetId($this->copyRow($table, $row));
                foreach ($children as $ct => $list) {
                    $col = $this->catalog->meta($ct)['parent'][1];
                    foreach ($list as $c) DB::table($ct)->insert(array_merge($this->freshIdentity($ct, $c), [$col => $newId]));
                }

                return (string) $newId;
            }
            if ($table === 'businesses') {
                DB::table('businesses')->where('id', $bid)->update(collect($row)->except(['id', 'ulid'])->all());
            } else {
                DB::table($table)->updateOrInsert($where, $row);
            }
            foreach ($children as $ct => $list) {
                $col = $this->catalog->meta($ct)['parent'][1];
                $this->catalog->query($ct, $bid)->where($col, $row['id'])->delete();
                foreach (array_chunk($list, 200) as $chunk) DB::table($ct)->insert($chunk);
            }

            return null;
        });

        $files = $this->ensureFiles($bid, $state, array_merge([$table => [$row]], $children));
        Audit::log($bid, 'backup.record_restored', $s, null, [
            'table' => $table, 'record' => $key, 'mode' => $mode, 'snapshot' => $s->ulid, 'new_record' => $result,
            'children' => array_sum(array_map('count', $children)),
        ]);

        return ['table' => $table, 'key' => $key, 'children' => array_sum(array_map('count', $children)), 'files' => $files, 'new_key' => $result];
    }

    /** Roll the whole business back to a backup point. A safety full backup of the current state is taken first. */
    public function rollbackBusiness(BackupSnapshot $s): BackupSnapshot
    {
        $business = Business::findOrFail($s->business_id);
        $state = $this->reader->state($s);
        $safety = $this->runner->run($business, 'pre_restore', 'full');
        if (! $safety->isComplete()) throw new DomainException('A safety backup of the current data could not be taken, so nothing was changed: '.$safety->error);
        $bid = $business->id;

        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($state, $bid) {
                $this->deferForeignKeys();
                DB::table('businesses')->where('id', $bid)->update(['logo_document_id' => null, 'zelle_qr_document_id' => null]);
                foreach (array_reverse(BackupCatalog::TABLES) as $t) {
                    if ($t === 'businesses' || in_array($t, BackupCatalog::PRESERVE_ON_ROLLBACK, true) || ! $this->catalog->exists($t)) continue;
                    $this->catalog->query($t, $bid)->delete();
                }
                $this->restoreUsers($state['users']);
                foreach (BackupCatalog::TABLES as $t) {
                    if ($t === 'businesses' || ! $this->catalog->exists($t)) continue;
                    $preserve = in_array($t, BackupCatalog::PRESERVE_ON_ROLLBACK, true);
                    foreach (array_chunk(array_values($state['rows'][$t] ?? []), 200) as $chunk) {
                        $preserve ? DB::table($t)->insertOrIgnore($chunk) : DB::table($t)->insert($chunk);
                    }
                }
                $biz = $state['rows']['businesses'][(string) $bid] ?? null;
                if ($biz) DB::table('businesses')->where('id', $bid)->update(collect($biz)->except(['id', 'ulid'])->all());
            });
        } finally {
            if ($mysql) Schema::enableForeignKeyConstraints();
        }
        $this->ensureFiles($bid, $state, ['private_documents' => array_values($state['rows']['private_documents'] ?? [])]);
        Audit::log($bid, 'backup.business_rolled_back', $s, null, ['snapshot' => $s->ulid, 'taken_at' => $s->started_at->toIso8601String(), 'safety_snapshot' => $safety->ulid]);

        return $safety;
    }

    private function deferForeignKeys(): void
    {
        // SQLite can't disable FK checks inside a transaction, but can defer them to commit.
        if (DB::getDriverName() === 'sqlite') DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    private function restoreUsers(array $users): void
    {
        foreach ($users as $u) {
            $taken = DB::table('users')->where('id', $u['id'])->orWhere(fn ($q) => $q->where('central_principal_type', $u['central_principal_type'] ?? null)
                ->where('central_principal_id', $u['central_principal_id'] ?? null))->exists();
            if (! $taken) DB::table('users')->insert($u);
        }
    }

    /** Makes sure document rows and their files exist again for anything that references them. */
    private function ensureFiles(int $bid, array $state, array $rowsByTable): int
    {
        $ids = [];
        foreach ($rowsByTable as $t => $rows) {
            foreach ($rows as $r) {
                if ($t === 'private_documents') $ids[] = (int) $r['id'];
                foreach ($r as $col => $v) {
                    if ($v && ($col === 'document_id' || str_ends_with($col, '_document_id'))) $ids[] = (int) $v;
                }
            }
        }
        $restored = 0;
        foreach (array_unique($ids) as $id) {
            $doc = $state['rows']['private_documents'][(string) $id] ?? null;
            if (! $doc) continue;
            if (! DB::table('private_documents')->where('id', $id)->exists()) DB::table('private_documents')->insert($doc);
            $path = rtrim((string) config('finance.storage.private_root'), '/').'/'.$doc['stored_path'];
            if (is_file($path)) continue;
            $file = $this->reader->file($bid, $id);
            if (! $file) continue;
            if (! is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
            file_put_contents($path, $file['bytes']);
            @chmod($path, 0640);
            $restored++;
        }

        return $restored;
    }

    private function freshIdentity(string $t, array $row): array
    {
        unset($row['id']);
        if (array_key_exists('ulid', $row)) $row['ulid'] = strtolower((string) Str::ulid());

        return $row;
    }

    private function copyRow(string $t, array $row): array
    {
        $row = $this->freshIdentity($t, $row);
        foreach (['display_name', 'name', 'title'] as $c) {
            if (! empty($row[$c])) {
                $row[$c] = Str::limit($row[$c], 160, '').' (restored copy)';
                break;
            }
        }
        if ($t === 'invoices') {
            $row = array_merge($row, [
                'number' => null, 'status' => 'draft', 'delivery_status' => 'not_sent', 'issue_date' => null, 'due_date' => null,
                'paid_cents' => 0, 'credited_cents' => 0, 'written_off_cents' => 0, 'balance_cents' => $row['total_cents'] ?? 0,
                'settlement_status' => 'unpaid', 'client_snapshot' => null, 'business_snapshot' => null, 'instructions_snapshot' => null,
                'template_snapshot' => null, 'issue_journal_entry_id' => null, 'issued_at' => null, 'issued_by' => null, 'voided_at' => null,
                'voided_by' => null, 'void_reason' => null, 'replaces_invoice_id' => null, 'last_reminder_at' => null, 'lock_version' => 0,
            ]);
        }
        if ($t === 'recurring_schedules' && array_key_exists('status', $row)) $row['status'] = 'paused';
        // Any other single-column unique value (e.g. SKU, code) gets a suffix so the copy can coexist.
        foreach (Schema::getIndexes($t) as $idx) {
            $cols = array_values(array_diff($idx['columns'], ['business_id']));
            if (! $idx['unique'] || $idx['primary'] || count($cols) !== 1 || in_array($cols[0], ['id', 'ulid', 'number'], true)) continue;
            if (is_string($row[$cols[0]] ?? null) && $row[$cols[0]] !== '') $row[$cols[0]] .= '-copy-'.Str::lower(Str::random(4));
        }
        if (array_key_exists('created_at', $row)) $row['created_at'] = now();
        if (array_key_exists('updated_at', $row)) $row['updated_at'] = now();

        return $row;
    }
}
