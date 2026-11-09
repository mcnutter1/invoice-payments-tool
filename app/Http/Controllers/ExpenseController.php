<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Documents\DocumentStore;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    private function expense(int $id): Expense
    {
        return Expense::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        $q = Expense::with('vendor', 'lines.account', 'client', 'project')->where('business_id', $b->id);
        if ($k = $request->query('kind')) $q->where('kind', $k);
        if ($src = $request->query('source')) $src === 'edited' ? $q->whereNotNull('edited_at') : $q->where('source', $src);
        if ($batch = $request->query('batch')) $q->where('import_batch_id', (int) $batch);
        if ($c = $request->query('client')) $c === 'none' ? $q->whereNull('client_id') : $q->where('client_id', (int) $c);
        if ($p = $request->query('project')) $p === 'none' ? $q->whereNull('project_id') : $q->where('project_id', (int) $p);
        if ($s = $request->query('status')) $q->where('status', $s);
        if ($v = $request->query('vendor')) $q->where('vendor_id', (int) $v);
        if ($from = $request->query('from')) $q->whereDate('expense_date', '>=', $from);
        if ($to = $request->query('to')) $q->whereDate('expense_date', '<=', $to);

        return view('expenses.index', [
            'expenses' => $q->orderByDesc('expense_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'vendors' => Vendor::where('business_id', $b->id)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::where('business_id', $b->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::where('business_id', $b->id)->orderBy('name')->get(['id', 'name', 'client_id']),
            'openBills' => (int) Expense::where('business_id', $b->id)->where('kind', 'bill')->whereIn('status', ['unpaid', 'partial'])->sum(\Illuminate\Support\Facades\DB::raw('total_cents - paid_cents')),
        ]);
    }

    private function formData(?Expense $e = null): array
    {
        $b = $this->biz();

        return [
            'vendors' => Vendor::where('business_id', $b->id)->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $e?->vendor_id))->orderBy('name')->get(),
            'clients' => Client::where('business_id', $b->id)->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $e?->client_id))->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::with('client')->where('business_id', $b->id)->where(fn ($q) => $q->where('status', 'open')->orWhere('id', $e?->project_id))->orderBy('name')->get(),
            'accounts' => ExpenseService::expenseAccounts($b),
            'funding' => ExpenseService::fundingAccounts($b),
        ];
    }

    public function create(Request $request)
    {
        return view('expenses.create', $this->formData() + [
            'kind' => $request->query('kind') === 'bill' ? 'bill' : 'expense',
            'expense' => null,
            'preset' => ['client_id' => $request->query('client'), 'project_id' => $request->query('project')],
        ]);
    }

    public function edit(int $id)
    {
        $e = $this->expense($id)->load('lines');
        abort_if($e->voided_at, 404);
        if ($e->source === 'mileage' && $e->mileageTrip) return $this->to('/mileage/'.$e->mileageTrip->id.'/edit');

        return view('expenses.create', $this->formData($e) + ['kind' => $e->kind, 'expense' => $e, 'preset' => []]);
    }

    public function update(Request $request, int $id, DocumentStore $docs)
    {
        $b = $this->biz();
        $e = $this->expense($id);
        $data = $this->validated($request, $docs, $b);
        $this->service->update($e, $data);

        return $this->to('/expenses/'.$e->id, 'Expense updated.');
    }

    public function store(Request $request, DocumentStore $docs)
    {
        $b = $this->biz();
        $data = $this->validated($request, $docs, $b);
        $expense = $this->service->create($b, $data);
        $msg = ($expense->kind === 'bill' ? 'Bill' : 'Expense').' recorded.'.($expense->possible_duplicate ? ' Note: an entry with the same vendor, date and amount already exists — check for a duplicate.' : '');

        return $this->to('/expenses/'.$expense->id, $msg);
    }

    private function validated(Request $request, DocumentStore $docs, $b): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['expense', 'bill'])],
            'project_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'new_vendor' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:80'],
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'payment_account_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(['card', 'ach', 'check', 'cash', 'zelle', 'other'])],
            'client_id' => ['nullable', 'integer'],
            'tax_treatment' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.billable' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'file', 'max:'.config('finance.storage.max_upload_kb')],
        ]);
        if (empty($data['vendor_id']) && ! empty($data['new_vendor'])) {
            $v = new Vendor(['name' => $data['new_vendor']]);
            $v->business_id = $b->id;
            $v->save();
            $data['vendor_id'] = $v->id;
        }
        if ($request->hasFile('receipt')) {
            $data['receipt_document_id'] = $docs->storeUpload($b, $request->file('receipt'), 'receipt')->id;
        }
        $data['lines'] = collect($data['lines'])->filter(fn ($l) => ($l['amount'] ?? '') !== '' && (float) $l['amount'] > 0)
            ->map(fn ($l) => ['account_id' => $l['account_id'] ?? 0, 'amount_cents' => $this->cents($l['amount']), 'description' => $l['description'] ?? null, 'billable' => ! empty($l['billable'])])
            ->values()->all();

        return $data;
    }

    public function show(int $id)
    {
        $e = $this->expense($id)->load('vendor', 'lines.account', 'vendorPayments.account', 'client', 'paymentAccount', 'project', 'mileageTrip', 'importBatch');

        return view('expenses.show', ['expense' => $e, 'funding' => ExpenseService::fundingAccounts($this->biz())]);
    }

    public function receipt(int $id, DocumentStore $docs)
    {
        $e = $this->expense($id);
        abort_unless($e->receipt_document_id, 404);

        return $docs->download($e->receipt, null, true);
    }

    public function attachReceipt(Request $request, int $id, DocumentStore $docs)
    {
        $request->validate(['receipt' => ['required', 'file', 'max:'.config('finance.storage.max_upload_kb')]]);
        $e = $this->expense($id);
        // Attaching a receipt never posts anything.
        $e->update(['receipt_document_id' => $docs->storeUpload($this->biz(), $request->file('receipt'), 'receipt')->id]);

        return back()->with('status', 'Receipt attached.');
    }

    public function void(Request $request, int $id)
    {
        $this->service->void($this->expense($id), $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return back()->with('status', 'Voided and reversed.');
    }

    public function pay(Request $request, int $id)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'account_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120']]);
        $this->service->payBill($this->expense($id), $this->cents($data['amount']), (int) $data['account_id'], $data['date'], $data['reference'] ?? null);

        return back()->with('status', 'Bill payment recorded.');
    }

    public function billToClient(int $id, InvoiceService $invoices)
    {
        $invoice = $this->service->billToClient($this->expense($id), $invoices);

        return $this->to('/invoices/'.$invoice->id.'/edit', 'Draft invoice created from billable expense lines.');
    }
}
