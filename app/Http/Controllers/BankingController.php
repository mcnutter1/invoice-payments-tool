<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChartAccount;
use App\Models\ImportBatch;
use App\Models\JournalLine;
use App\Models\MoneyMovement;
use App\Models\PrivateDocument;
use App\Models\ReconciliationSession;
use App\Services\Banking\BankingService;
use App\Services\Documents\DocumentStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankingController extends Controller
{
    public function __construct(private readonly BankingService $service) {}

    private function bankAccount(int $id): BankAccount
    {
        return BankAccount::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    private function txn(int $id): BankTransaction
    {
        return BankTransaction::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index()
    {
        $b = $this->biz();
        $accounts = BankAccount::with('chartAccount')->where('business_id', $b->id)->get();
        $balances = app(\App\Services\Reports\ReportService::class)->balances($b, null, $b->localToday()->toDateString());

        return view('banking.index', [
            'accounts' => $accounts,
            'balances' => $balances,
            'unmatched' => BankTransaction::where('business_id', $b->id)->where('status', 'unmatched')->selectRaw('bank_account_id, count(*) c')->groupBy('bank_account_id')->pluck('c', 'bank_account_id'),
            'movements' => MoneyMovement::with('fromAccount', 'toAccount')->where('business_id', $b->id)->latest('movement_date')->limit(10)->get(),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['bank', 'credit_card'])],
            'institution' => ['nullable', 'string', 'max:120'],
            'last4' => ['nullable', 'digits:4'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],
        ]);
        $data['opening_balance_cents'] = $this->cents($data['opening_balance'] ?? 0);
        $acct = $this->service->createAccount($this->biz(), $data);

        return $this->to('/banking/'.$acct->id, 'Account added.');
    }

    public function account(Request $request, int $id)
    {
        $acct = $this->bankAccount($id)->load('chartAccount');
        $status = $request->query('status', 'unmatched');
        $q = BankTransaction::where('business_id', $acct->business_id)->where('bank_account_id', $acct->id);
        if ($status !== 'all') $q->where('status', $status);
        $txns = $q->orderByDesc('posted_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $b = $this->biz();

        return view('banking.account', [
            'account' => $acct,
            'txns' => $txns,
            'status' => $status,
            'suggestions' => $txns->getCollection()->where('status', 'unmatched')->mapWithKeys(fn ($t) => [$t->id => $this->service->suggestions($t)->take(3)]),
            'categories' => ChartAccount::where('business_id', $b->id)->where('is_active', true)->where('id', '!=', $acct->chart_account_id)
                ->whereNotIn('system_key', ['ar', 'ap'])->orderBy('code')->get(),
            'batches' => ImportBatch::where('business_id', $b->id)->where('bank_account_id', $acct->id)->latest()->limit(10)->get(),
            'reconciliations' => ReconciliationSession::where('bank_account_id', $acct->id)->latest('statement_date')->limit(10)->get(),
            'ledgerBalance' => (int) JournalLine::where('business_id', $b->id)->where('account_id', $acct->chart_account_id)->sum(\Illuminate\Support\Facades\DB::raw('debit_cents - credit_cents')),
        ]);
    }

    public function upload(Request $request, int $id, DocumentStore $docs)
    {
        $acct = $this->bankAccount($id);
        $request->validate(['csv' => ['required', 'file', 'max:5120']]);
        $file = $request->file('csv');
        $contents = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8') && ! mb_check_encoding($contents, 'ASCII')) {
            throw new DomainException('The file must be a UTF-8 CSV export.');
        }
        $this->service->parseCsv($contents);
        $doc = $docs->put($this->biz(), 'import_csv', $file->getClientOriginalName(), $contents, 'text/csv');

        return $this->to('/banking/'.$acct->id.'/import/'.$doc->ulid);
    }

    private function csvDoc(string $ulid): PrivateDocument
    {
        return PrivateDocument::where('business_id', $this->biz()->id)->where('kind', 'import_csv')->where('ulid', $ulid)->firstOrFail();
    }

    private function mapping(Request $request, array $header): array
    {
        $guess = function (array $needles) use ($header) {
            foreach ($header as $i => $h) {
                foreach ($needles as $n) if (str_contains(strtolower($h), $n)) return $i;
            }

            return null;
        };

        return [
            'date' => (int) $request->input('map_date', $guess(['date']) ?? 0),
            'description' => (int) $request->input('map_description', $guess(['desc', 'memo', 'payee', 'name']) ?? 1),
            'amount' => $request->has('map_amount') ? ($request->input('map_amount') === '' ? null : (int) $request->input('map_amount')) : $guess(['amount']),
            'debit' => $request->input('map_debit') !== null && $request->input('map_debit') !== '' ? (int) $request->input('map_debit') : $guess(['debit', 'withdraw']),
            'credit' => $request->input('map_credit') !== null && $request->input('map_credit') !== '' ? (int) $request->input('map_credit') : $guess(['credit', 'deposit']),
            'date_format' => in_array($request->input('date_format'), ['', 'm/d/Y', 'Y-m-d', 'd/m/Y', 'm/d/y', 'n/j/Y'], true) ? (string) $request->input('date_format') : '',
            'invert' => $request->boolean('invert'),
        ];
    }

    public function preview(Request $request, int $id, string $doc)
    {
        $acct = $this->bankAccount($id);
        $csv = $this->service->parseCsv($this->csvDoc($doc)->contents());
        $mapping = $this->mapping($request, $csv['header']);
        $rows = $this->service->normalize($acct, $csv['rows'], $mapping);

        return view('banking.preview', ['account' => $acct, 'doc' => $doc, 'header' => $csv['header'], 'mapping' => $mapping, 'rows' => $rows,
            'sample' => array_slice($csv['rows'], 0, 3)]);
    }

    public function import(Request $request, int $id, string $doc)
    {
        $acct = $this->bankAccount($id);
        $file = $this->csvDoc($doc);
        $csv = $this->service->parseCsv($file->contents());
        $mapping = $this->mapping($request, $csv['header']);
        $batch = $this->service->import($acct, $file->original_name, $this->service->normalize($acct, $csv['rows'], $mapping), $mapping);

        return $this->to('/banking/'.$acct->id, "Imported {$batch->imported_count} rows ({$batch->duplicate_count} duplicates skipped, {$batch->error_count} errors). Nothing was posted to the ledger yet.");
    }

    public function undoImport(int $id)
    {
        $batch = ImportBatch::where('business_id', $this->biz()->id)->findOrFail($id);
        $this->service->undoBatch($batch);

        return back()->with('status', 'Import undone.');
    }

    public function match(Request $request, int $id)
    {
        $txn = $this->txn($id);
        $line = JournalLine::where('business_id', $txn->business_id)->findOrFail((int) $request->input('journal_line_id'));
        $this->service->match($txn, $line);

        return back()->with('status', 'Matched.');
    }

    public function categorize(Request $request, int $id)
    {
        $data = $request->validate(['account_id' => ['required', 'integer'], 'memo' => ['nullable', 'string', 'max:200']]);
        $this->service->categorize($this->txn($id), (int) $data['account_id'], $data['memo'] ?? null);

        return back()->with('status', 'Posted to the ledger.');
    }

    public function exclude(int $id)
    {
        $this->service->exclude($this->txn($id));

        return back()->with('status', 'Excluded.');
    }

    public function undo(int $id)
    {
        $this->service->undo($this->txn($id));

        return back()->with('status', 'Undone.');
    }

    public function movementForm()
    {
        $b = $this->biz();

        return view('banking.movement', [
            'accounts' => ChartAccount::where('business_id', $b->id)->where('is_active', true)->whereIn('type', ['asset', 'liability'])->whereNotIn('system_key', ['ar', 'ap', 'customer_advances', 'sales_tax'])->orderBy('code')->get(),
        ]);
    }

    public function storeMovement(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(MoneyMovement::KINDS))],
            'movement_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_account_id' => ['nullable', 'integer'],
            'to_account_id' => ['nullable', 'integer'],
            'memo' => ['nullable', 'string', 'max:200'],
        ]);
        $data['amount_cents'] = $this->cents($data['amount']);
        $this->service->recordMovement($this->biz(), $data);

        return $this->to('/banking', 'Recorded. Transfers, owner activity and loan principal never affect profit & loss.');
    }

    public function startReconciliation(Request $request, int $id)
    {
        $data = $request->validate(['statement_date' => ['required', 'date'], 'closing' => ['required', 'numeric'], 'opening' => ['nullable', 'numeric']]);
        $s = $this->service->startReconciliation($this->bankAccount($id), $data['statement_date'], $this->cents($data['closing']), isset($data['opening']) && $data['opening'] !== null ? $this->cents($data['opening']) : null);

        return $this->to('/reconciliations/'.$s->id);
    }

    private function session(int $id): ReconciliationSession
    {
        return ReconciliationSession::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function reconciliation(int $id)
    {
        $s = $this->session($id)->load('bankAccount.chartAccount');
        $lines = $s->status === 'in_progress' ? $this->service->reconcilableLines($s) : JournalLine::with('entry')->where('reconciliation_id', $s->id)->orderBy('entry_date')->get();
        $matchedIds = BankTransaction::where('bank_account_id', $s->bank_account_id)->whereNotNull('matched_journal_line_id')->pluck('matched_journal_line_id')->all();

        return view('banking.reconcile', ['session' => $s, 'lines' => $lines, 'matchedIds' => $matchedIds,
            'sign' => $s->bankAccount->chartAccount->type === 'asset' ? 1 : -1]);
    }

    public function completeReconciliation(Request $request, int $id)
    {
        $this->service->completeReconciliation($this->session($id), array_map('intval', (array) $request->input('lines', [])));

        return $this->to('/reconciliations/'.$id, 'Reconciliation completed.');
    }

    public function cancelReconciliation(int $id)
    {
        $s = $this->session($id);
        if ($s->status === 'in_progress') $s->update(['status' => 'cancelled']);

        return $this->to('/banking/'.$s->bank_account_id, 'Reconciliation cancelled.');
    }

    public function undoReconciliation(Request $request, int $id)
    {
        $this->service->undoReconciliation($this->session($id), $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return back()->with('status', 'Reconciliation undone.');
    }
}
