<?php

namespace App\Services\Backups;

use App\Models\BackupRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Builds the text the backup explorer searches: a record's own fields plus the names of what it relates to. */
class BackupSearchIndex
{
    private const SKIP = ['ulid', 'sha256', 'signature_hash', 'otp_hash', 'token_hash', 'tracking_hash', 'encrypted_body', 'tax_id_encrypted',
        'stored_path', 'correlation_id', 'user_agent', 'ip', 'currency', 'status', 'kind', 'role', 'type'];

    public function __construct(private readonly BackupCatalog $catalog) {}

    /** Names of related records, keyed by foreign-key column, loaded once per business. */
    public function maps(int $businessId): array
    {
        $q = fn (string $t) => $this->catalog->exists($t) ? DB::table($t)->where('business_id', $businessId) : null;
        $signers = $this->catalog->exists('contract_recipients')
            ? DB::table('contract_recipients')->where('contract_recipients.business_id', $businessId)
                ->join('contract_versions', 'contract_versions.id', '=', 'contract_recipients.contract_version_id')
                ->get(['contract_versions.contract_id', 'contract_recipients.name', 'contract_recipients.email'])
                ->groupBy('contract_id')->map(fn ($g) => $g->map(fn ($r) => $r->name.' '.$r->email)->unique()->implode(' '))
            : collect();

        return [
            'client_id' => $q('clients')?->get(['id', 'display_name', 'legal_name'])->mapWithKeys(fn ($r) => [$r->id => trim($r->display_name.' '.$r->legal_name)])->all() ?? [],
            'vendor_id' => $q('vendors')?->pluck('name', 'id')->all() ?? [],
            'contract_id' => $q('contracts')?->get(['id', 'number', 'title'])->mapWithKeys(fn ($r) => [$r->id => trim($r->number.' '.$r->title)])->all() ?? [],
            'contract_version_id' => $q('contract_versions')?->pluck('title', 'id')->all() ?? [],
            'invoice_id' => $q('invoices')?->pluck('number', 'id')->all() ?? [],
            '_signers' => $signers->all(),
        ];
    }

    public function text(string $table, array $row, array $maps): string
    {
        $parts = [$this->catalog->label($table, $row)];
        foreach ($row as $col => $v) {
            if ($v === null || $v === '' || in_array($col, self::SKIP, true) || str_ends_with($col, '_at') || str_ends_with($col, '_hash')) continue;
            if (str_ends_with($col, '_id') || $col === 'id') {
                if (isset($maps[$col][$v])) $parts[] = $maps[$col][$v];
                continue;
            }
            if (is_string($v) && ($v[0] === '{' || $v[0] === '[')) {
                // Snapshots such as client_snapshot carry the client's name at the time.
                $d = json_decode($v, true);
                if (is_array($d)) foreach (['display_name', 'legal_name', 'name', 'email', 'title'] as $k) if (isset($d[$k]) && is_string($d[$k])) $parts[] = $d[$k];
                continue;
            }
            if (is_string($v) && mb_strlen($v) <= 300) $parts[] = $v;
        }
        if ($table === 'contracts' && isset($maps['_signers'][$row['id'] ?? null])) $parts[] = $maps['_signers'][$row['id']];
        if ($table === 'contract_versions' && isset($maps['contract_id'][$row['contract_id'] ?? null])) $parts[] = $maps['contract_id'][$row['contract_id']];

        return Str::limit(implode(' · ', array_unique(array_filter(array_map('trim', $parts)))), 2000, '');
    }

    /** Fill search text for records indexed before it existed, from their current rows. */
    public function backfill(int $businessId, int $limit = 5000): int
    {
        $missing = BackupRecord::where('business_id', $businessId)->whereNull('search_text')->where('deleted', false)->limit($limit)->get()->groupBy('table_name');
        if ($missing->isEmpty()) return 0;
        $maps = $this->maps($businessId);
        $n = 0;
        foreach ($missing as $table => $records) {
            if (! $this->catalog->exists($table)) continue;
            foreach ($records as $rec) {
                $row = $this->catalog->query($table, $businessId)->where($this->catalog->keyWhere($table, $rec->record_key))->first();
                $rec->update(['search_text' => $row ? $this->text($table, (array) $row, $maps) : $rec->label]);
                $n++;
            }
        }

        return $n;
    }
}
