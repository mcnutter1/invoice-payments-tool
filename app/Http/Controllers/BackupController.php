<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\BackupChange;
use App\Models\BackupFile;
use App\Models\BackupRecord;
use App\Models\BackupSnapshot;
use App\Services\Audit;
use App\Services\Backups\BackupCatalog;
use App\Services\Backups\BackupReader;
use App\Services\Backups\BackupRestorer;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Backup explorer for one business: find records across backup points, preview versions and restore. */
class BackupController extends Controller
{
    public function __construct(private readonly BackupReader $reader, private readonly BackupCatalog $catalog) {}

    private function snapshot(string $ulid): BackupSnapshot
    {
        return BackupSnapshot::where('business_id', $this->biz()->id)->where('ulid', strtolower($ulid))->firstOrFail();
    }

    private function table(string $t): string
    {
        abort_unless(in_array($t, BackupCatalog::TABLES, true), 404);

        return $t;
    }

    public function index(Request $request, BackupSettings $settings)
    {
        $b = $this->biz();
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $records = null;
        if ($q !== '' || $type) {
            $records = BackupRecord::where('business_id', $b->id)
                ->when($type && isset(BackupCatalog::ENTITIES[$type]), fn ($x) => $x->where('table_name', $type))
                ->when($q !== '', fn ($x) => $x->where(fn ($x) => $x->where('label', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')->orWhere('record_key', $q)))
                ->orderByDesc('last_changed_at')->limit(100)->get();
        }

        return view('backups.index', [
            'q' => $q, 'type' => $type, 'records' => $records,
            'recent' => BackupChange::with('snapshot')->where('business_id', $b->id)
                ->when($type && isset(BackupCatalog::ENTITIES[$type]), fn ($x) => $x->where('table_name', $type))
                ->where('table_name', '!=', 'private_documents')->latest('id')->limit(40)->get(),
            'snapshots' => BackupSnapshot::where('business_id', $b->id)->latest('id')->paginate(30)->withQueryString(),
            'configured' => $settings->configured(),
            'nextType' => app(BackupRunner::class)->nextType($b),
        ]);
    }

    public function snapshotShow(string $ulid)
    {
        $s = $this->snapshot($ulid);

        return view('backups.snapshot', [
            'snap' => $s,
            'changes' => BackupChange::where('snapshot_id', $s->id)->orderBy('table_name')->orderBy('id')->paginate(100),
            'chain' => $this->reader->chain($s),
        ]);
    }

    public function record(Request $request, string $table, string $key)
    {
        $b = $this->biz();
        $table = $this->table($table);
        $changes = BackupChange::with('snapshot')->where('business_id', $b->id)->where('table_name', $table)->where('record_key', $key)
            ->orderByDesc('snapshot_id')->get()->filter(fn ($c) => $c->snapshot)->values();
        abort_if($changes->isEmpty(), 404);
        $selected = $changes->firstWhere(fn ($c) => $c->snapshot->ulid === $request->query('v') && $c->action !== 'deleted')
            ?? $changes->first(fn ($c) => $c->action !== 'deleted');
        $version = $previous = null;
        $error = null;
        if ($selected) {
            try {
                $version = $this->reader->version($selected->snapshot, $table, $key);
                $prev = $changes->first(fn ($c) => $c->snapshot_id < $selected->snapshot_id && $c->action !== 'deleted');
                $previous = $prev ? $this->reader->version($prev->snapshot, $table, $key) : null;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $current = $this->catalog->query($table, $b->id)->where($this->catalog->keyWhere($table, $key))->first();

        return view('backups.record', [
            'table' => $table, 'key' => $key, 'changes' => $changes, 'selected' => $selected,
            'version' => $version, 'previous' => $previous, 'current' => $current ? (array) $current : null, 'error' => $error,
            'label' => BackupRecord::where('business_id', $b->id)->where('table_name', $table)->where('record_key', $key)->value('label') ?? '#'.$key,
            'files' => $this->filesFor($table, $version),
            'copyable' => in_array($table, BackupCatalog::COPYABLE, true),
            'children' => array_map(fn ($c) => BackupCatalog::title($c), $this->catalog->children($table)),
        ]);
    }

    /** Backed-up files a version references, for previews. */
    private function filesFor(string $table, ?array $row): array
    {
        if (! $row) return [];
        $ids = [];
        if ($table === 'private_documents') $ids[] = (int) $row['id'];
        foreach ($row as $col => $v) {
            if ($v && ($col === 'document_id' || str_ends_with($col, '_document_id'))) $ids[] = (int) $v;
        }
        if ($table === 'invoices') {
            $ids = array_merge($ids, DB::table('invoice_document_versions')->where('business_id', $this->biz()->id)->where('invoice_id', $row['id'])->pluck('document_id')->all());
        }

        return BackupFile::where('business_id', $this->biz()->id)->whereIn('document_id', array_unique($ids))->get()->all();
    }

    public function file(int $document)
    {
        $f = $this->reader->file($this->biz()->id, $document) ?? abort(404);
        $inline = in_array($f['mime'], ['application/pdf', 'image/png', 'image/jpeg'], true);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($f['name'] ?: 'file-'.$document));

        return response($f['bytes'], 200, [
            'Content-Type' => $inline ? $f['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function restoreRecord(Request $request, string $ulid, string $table, string $key, BackupRestorer $restorer)
    {
        $s = $this->snapshot($ulid);
        $table = $this->table($table);
        $data = $request->validate(['mode' => ['required', Rule::in(array_keys(BackupRestorer::MODES))], 'children' => ['nullable', 'boolean']]);
        $r = $restorer->restoreRecord($s, $table, $key, $data['mode'], $request->boolean('children', true));
        $what = BackupCatalog::title($table).' #'.$key;
        $msg = match ($data['mode']) {
            'copy' => $what.' was restored as a new copy (#'.$r['new_key'].').',
            default => $what.' was restored from the backup of '.$s->started_at->timezone($this->biz()->timezone)->format('M j, g:ia').'.',
        };
        if ($r['children']) $msg .= ' Included '.$r['children'].' related line(s).';
        if ($r['files']) $msg .= ' Recovered '.$r['files'].' file(s).';

        return back()->with('status', $msg);
    }

    public function rollback(Request $request, string $ulid, BackupRestorer $restorer)
    {
        $s = $this->snapshot($ulid);
        $data = $request->validate(['confirm' => ['required', 'string']]);
        if (trim($data['confirm']) !== $this->biz()->name()) throw new DomainException('Type the business name exactly to confirm.');
        $safety = $restorer->rollbackBusiness($s);

        return $this->to('/backups', 'The business was restored to '.$s->started_at->timezone($this->biz()->timezone)->format('M j, Y g:ia')
            .'. The data from just before the restore was saved as backup '.substr($safety->ulid, -8).'.');
    }

    public function run(BackupRunner $runner, BackupSettings $settings)
    {
        if (! $settings->configured()) throw new DomainException('Backup storage hasn’t been set up by an administrator yet.');
        if (! $this->biz()->backups_enabled) throw new DomainException('Backups aren’t enabled for this business. Ask an administrator to turn them on.');
        $snap = $runner->run($this->biz(), 'manual');
        Audit::log($this->biz(), 'backup.manual_run', $snap, null, ['type' => $snap->type, 'status' => $snap->status]);

        return back()->with($snap->isComplete() ? 'status' : 'error', $snap->isComplete() ? ucfirst($snap->type).' backup completed.' : 'Backup failed: '.$snap->error);
    }

    public function export(string $ulid)
    {
        $s = $this->snapshot($ulid);
        $state = $this->reader->state($s);
        Audit::log($this->biz(), 'backup.exported', $s);
        $name = 'backup-'.$this->biz()->ulid.'-'.$s->started_at->format('Ymd-His').'.json';

        return response()->streamDownload(function () use ($s, $state) {
            echo json_encode(['business' => $this->biz()->only(['ulid', 'legal_name', 'display_name']), 'taken_at' => $s->started_at->toIso8601String(),
                'tables' => array_map('array_values', $state['rows']), 'users' => $state['users']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $name, ['Content-Type' => 'application/json']);
    }
}
