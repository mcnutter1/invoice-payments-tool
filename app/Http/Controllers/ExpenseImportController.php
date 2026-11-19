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
use App\Services\Expenses\Subscriptions\SubscriptionChargeImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Upload → review/map → import for expense CSVs (Monarch Money export format) and pulled subscription charges. */
class ExpenseImportController extends Controller
{
    public function __construct(private readonly ExpenseImportService $service, private readonly SubscriptionChargeImporter $subscriptions) {}

    public function index()
    {
        return view('expenses.import', [
            'batches' => ImportBatch::where('business_id', $this->biz()->id)->whereIn('kind', ['expense_csv', SubscriptionChargeImporter::BATCH_KIND])->latest('id')->limit(20)->get(),
            'sources' => $this->subscriptions->overview($this->biz()),
        ]);
    }

    public function pullSubscriptions(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'sources' => ['required', 'array', 'min:1'], 'sources.*' => [Rule::in(array_keys($this->subscriptions->sources()))],
            'since' => ['required', Rule::in(['30', '90', '180', '365', 'ytd'])],
        ], ['sources.required' => 'Choose at least one source.']);
        $today = $b->localToday();
        $from = $data['since'] === 'ytd' ? $today->copy()->startOfYear() : $today->copy()->subDays((int) $data['since']);
        $doc = $this->subscriptions->pull($b, $data['sources'], $from, $today);

        return $this->to('/expenses/import/'.$doc->ulid);
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
        return PrivateDocument::where('business_id', $this->biz()->id)->whereIn('kind', ['import_csv', SubscriptionChargeImporter::DOC_KIND])->where('ulid', $ulid)->firstOrFail();
    }

    private function previewDoc(PrivateDocument $doc): array
    {
        return $doc->kind === SubscriptionChargeImporter::DOC_KIND
            ? $this->service->previewCharges($this->biz(), SubscriptionChargeImporter::snapshot($doc))
            : $this->service->preview($this->biz(), $doc->contents());
    }

    public function review(string $ulid)
    {
        $b = $this->biz();
        $doc = $this->doc($ulid);
        $p = $this->previewDoc($doc);

        return view('expenses.import-review', [
            'doc' => $doc, 'p' => $p,
            'snapshot' => $doc->kind === SubscriptionChargeImporter::DOC_KIND ? SubscriptionChargeImporter::snapshot($doc) : null,
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
        $kind = $doc->kind === SubscriptionChargeImporter::DOC_KIND ? SubscriptionChargeImporter::BATCH_KIND : 'expense_csv';
        $label = $kind === 'expense_csv' ? $doc->original_name : SubscriptionChargeImporter::label(SubscriptionChargeImporter::snapshot($doc));
        $batch = $this->service->commit($this->biz(), $this->previewDoc($doc), $label, $data, $kind);

        return $this->to('/expenses?batch='.$batch->id, $batch->imported_count.' expense'.($batch->imported_count === 1 ? '' : 's').' imported'
            .($batch->error_count ? ', '.$batch->error_count.' skipped (closed period or missing mapping)' : '').'.');
    }

    public function undo(int $id)
    {
        $batch = ImportBatch::where('business_id', $this->biz()->id)->whereIn('kind', ['expense_csv', SubscriptionChargeImporter::BATCH_KIND])->whereNull('undone_at')->findOrFail($id);
        $r = $this->service->undo($batch);

        return back()->with('status', $r['voided'].' imported expenses voided.'.($r['kept'] ? ' '.$r['kept'].' were kept because they were edited, billed or are in a closed period.' : ''));
    }
}
