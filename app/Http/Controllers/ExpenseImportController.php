<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\PrivateDocument;
use App\Models\Project;
use App\Services\Documents\DocumentStore;
use App\Services\Expenses\ExpenseImportService;
use App\Services\Expenses\ExpenseService;
use Illuminate\Http\Request;

/** Upload → review/map → import for expense CSVs (Monarch Money export format). */
class ExpenseImportController extends Controller
{
    public function __construct(private readonly ExpenseImportService $service) {}

    public function index()
    {
        return view('expenses.import', [
            'batches' => ImportBatch::where('business_id', $this->biz()->id)->where('kind', 'expense_csv')->latest('id')->limit(20)->get(),
        ]);
    }

    public function upload(Request $request, DocumentStore $docs)
    {
        $request->validate(['csv' => ['required', 'file', 'max:5120']]);
        $file = $request->file('csv');
        $contents = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8')) throw new DomainException('The file must be a UTF-8 CSV export.');
        $this->service->preview($this->biz(), $contents); // validates headers
        $doc = $docs->put($this->biz(), 'import_csv', $file->getClientOriginalName(), $contents, 'text/csv');

        return $this->to('/expenses/import/'.$doc->ulid);
    }

    private function doc(string $ulid): PrivateDocument
    {
        return PrivateDocument::where('business_id', $this->biz()->id)->where('kind', 'import_csv')->where('ulid', $ulid)->firstOrFail();
    }

    public function review(string $ulid)
    {
        $b = $this->biz();
        $doc = $this->doc($ulid);
        $p = $this->service->preview($b, $doc->contents());

        return view('expenses.import-review', [
            'doc' => $doc, 'p' => $p,
            'catMap' => $this->service->suggestCategories($b, $p['categories']),
            'acctMap' => $this->service->suggestAccounts($b, $p['accounts']),
            'accounts' => ExpenseService::expenseAccounts($b),
            'funding' => ExpenseService::fundingAccounts($b),
            'clients' => Client::where('business_id', $b->id)->where('status', 'active')->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::with('client')->where('business_id', $b->id)->where('status', 'open')->orderBy('name')->get(),
        ]);
    }

    public function commit(Request $request, string $ulid)
    {
        $data = $request->validate([
            'include' => ['array'], 'include.*' => ['integer'],
            'categories' => ['array'], 'categories.*' => ['nullable', 'integer'],
            'accounts' => ['array'], 'accounts.*' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'], 'project_id' => ['nullable', 'integer'],
            'entity' => ['nullable', 'string', 'max:200'], 'remember' => ['nullable', 'boolean'],
        ]);
        // Category / account names are posted as base64 keys so spaces, dots and brackets survive form encoding.
        foreach (['categories', 'accounts'] as $k) {
            $data[$k] = collect($data[$k] ?? [])->mapWithKeys(fn ($v, $key) => [(string) base64_decode((string) $key, true) => $v])->all();
        }
        $doc = $this->doc($ulid);
        $batch = $this->service->commit($this->biz(), $doc->contents(), $doc->original_name, $data);

        return $this->to('/expenses?batch='.$batch->id, $batch->imported_count.' expense'.($batch->imported_count === 1 ? '' : 's').' imported'
            .($batch->error_count ? ', '.$batch->error_count.' skipped (closed period or missing mapping)' : '').'.');
    }

    public function undo(int $id)
    {
        $batch = ImportBatch::where('business_id', $this->biz()->id)->where('kind', 'expense_csv')->whereNull('undone_at')->findOrFail($id);
        $r = $this->service->undo($batch);

        return back()->with('status', $r['voided'].' imported expenses voided.'.($r['kept'] ? ' '.$r['kept'].' were kept because they were edited, billed or are in a closed period.' : ''));
    }
}
