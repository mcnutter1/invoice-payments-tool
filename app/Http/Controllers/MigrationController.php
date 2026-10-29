<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\ImportBatch;
use App\Models\PrivateDocument;
use App\Services\Banking\BankingService;
use App\Services\Documents\DocumentStore;
use App\Services\Migration\MigrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MigrationController extends Controller
{
    public function __construct(private readonly MigrationService $service, private readonly BankingService $csv) {}

    public function index()
    {
        return view('migration.index', [
            'kinds' => MigrationService::KINDS,
            'batches' => ImportBatch::where('business_id', $this->biz()->id)->where('kind', 'like', 'migration_%')->latest()->limit(20)->get(),
        ]);
    }

    public function template(string $kind)
    {
        abort_unless(isset(MigrationService::KINDS[$kind]), 404);

        return response($this->service->template($kind), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.$kind.'-template.csv"']);
    }

    public function upload(Request $request, DocumentStore $docs)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(MigrationService::KINDS))], 'csv' => ['required', 'file', 'max:5120'], 'cutoff' => ['nullable', 'date']]);
        $contents = (string) file_get_contents($request->file('csv')->getRealPath());
        $this->csv->parseCsv($contents);
        $doc = $docs->put($this->biz(), 'import_csv', $request->file('csv')->getClientOriginalName(), $contents, 'text/csv');

        return $this->to('/migration/'.$data['kind'].'/'.$doc->ulid.($data['cutoff'] ?? null ? '?cutoff='.$data['cutoff'] : ''));
    }

    private function load(string $kind, string $doc, ?string $cutoff): array
    {
        abort_unless(isset(MigrationService::KINDS[$kind]), 404);
        $file = PrivateDocument::where('business_id', $this->biz()->id)->where('kind', 'import_csv')->where('ulid', $doc)->firstOrFail();
        $parsed = $this->csv->parseCsv($file->contents());

        return [$file, $this->service->preview($this->biz(), $kind, $parsed['header'], $parsed['rows'], $cutoff)];
    }

    public function preview(Request $request, string $kind, string $doc)
    {
        $cutoff = $request->query('cutoff');
        [$file, $rows] = $this->load($kind, $doc, $cutoff);

        return view('migration.preview', ['kind' => $kind, 'label' => MigrationService::KINDS[$kind][0], 'doc' => $doc, 'rows' => $rows, 'cutoff' => $cutoff, 'file' => $file]);
    }

    public function commit(Request $request, string $kind, string $doc)
    {
        $cutoff = $request->input('cutoff');
        [$file, $rows] = $this->load($kind, $doc, $cutoff);
        if (! collect($rows)->where('status', 'new')->count()) throw new DomainException('Nothing new to import.');
        $batch = $this->service->commit($this->biz(), $kind, $file->original_name, $rows, $cutoff);

        return $this->to('/migration', "Imported {$batch->imported_count} rows ({$batch->duplicate_count} duplicates and {$batch->error_count} errors skipped).");
    }
}
