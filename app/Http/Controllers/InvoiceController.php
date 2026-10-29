<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ContractVersion;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\InvoiceDocumentVersion;
use App\Models\Product;
use App\Models\PublicToken;
use App\Models\TaxRate;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Invoicing\InvoicePresenter;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $service) {}

    private function invoice(int $id): Invoice
    {
        return Invoice::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        $q = Invoice::with('client')->where('business_id', $b->id);
        $status = $request->query('status', 'all');
        $today = $b->localToday()->toDateString();
        match ($status) {
            'draft' => $q->where('status', 'draft'),
            'open' => $q->where('status', 'issued')->where('balance_cents', '>', 0),
            'overdue' => $q->where('status', 'issued')->where('balance_cents', '>', 0)->whereDate('due_date', '<', $today),
            'paid' => $q->where('status', 'issued')->where('settlement_status', 'paid'),
            'void' => $q->where('status', 'void'),
            'unsent' => $q->where('status', 'issued')->whereIn('delivery_status', ['not_sent', 'failed', 'unknown']),
            default => null,
        };
        if ($cid = $request->query('client')) $q->where('client_id', (int) $cid);
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhereHas('client', fn ($c) => $c->where('display_name', 'like', $like)));
        }
        if ($from = $request->query('from')) $q->whereDate('issue_date', '>=', $from);
        if ($to = $request->query('to')) $q->whereDate('issue_date', '<=', $to);
        $sort = $request->query('sort', '-id');
        $col = ltrim($sort, '-');
        if (! in_array($col, ['id', 'issue_date', 'due_date', 'total_cents', 'balance_cents', 'number'], true)) $col = 'id';
        $q->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc');

        return view('invoices.index', ['invoices' => $q->paginate(25)->withQueryString(), 'status' => $status, 'sort' => $sort]);
    }

    private function editorData(?Invoice $invoice, ?int $clientId = null): array
    {
        $b = $this->biz();

        return [
            'invoice' => $invoice,
            'clients' => Client::where('business_id', $b->id)->where('status', 'active')->orderBy('display_name')->get(['id', 'display_name', 'tax_exempt', 'default_terms_days', 'email']),
            'products' => Product::where('business_id', $b->id)->where('active', true)->orderBy('name')->get(),
            'taxRates' => TaxRate::where('business_id', $b->id)->where('active', true)->orderBy('name')->get(),
            'incomeAccounts' => ChartAccount::where('business_id', $b->id)->where('type', 'income')->where('is_active', true)->orderBy('code')->get(),
            'contractVersions' => ContractVersion::with('contract')->where('business_id', $b->id)->whereIn('status', ['completed'])->get(),
            'selectedClient' => $clientId,
        ];
    }

    public function create(Request $request)
    {
        return view('invoices.editor', $this->editorData(null, (int) $request->query('client') ?: null));
    }

    private function validated(Request $request): array
    {
        $b = $this->biz();

        return $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id')->where('business_id', $b->id)],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'service_start' => ['nullable', 'date'],
            'service_end' => ['nullable', 'date', 'after_or_equal:service_start'],
            'po_number' => ['nullable', 'string', 'max:80'],
            'contract_version_id' => ['nullable', 'integer'],
            'discount_type' => ['required', Rule::in(['none', 'percent', 'amount'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'customer_note' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => [Rule::in(['zelle', 'check'])],
            'recipients' => ['nullable', 'string', 'max:1000'],
            'lock_version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:200'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:30'],
            'lines.*.income_account_id' => ['nullable', 'integer'],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.contract_milestone_id' => ['nullable', 'integer'],
        ]);
    }

    private function payload(Request $request): array
    {
        $data = $this->validated($request);
        $data['recipients'] = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($data['recipients'] ?? ''))));
        $data['payment_methods'] = $data['payment_methods'] ?? [];
        $data['discount_value'] = (string) ($data['discount_value'] ?? '0');

        return $data;
    }

    public function store(Request $request)
    {
        $invoice = $this->service->saveDraft($this->biz(), null, $this->payload($request));
        Audit::log($this->biz(), 'invoice.draft_created', $invoice);

        return $this->afterSave($request, $invoice);
    }

    public function edit(int $id)
    {
        $invoice = $this->invoice($id)->load('lines');
        if (! $invoice->isDraft()) return $this->to('/invoices/'.$id)->with('error', 'Issued invoices cannot be edited. Void & reissue or create a credit note.');

        return view('invoices.editor', $this->editorData($invoice));
    }

    public function update(Request $request, int $id)
    {
        $invoice = $this->service->saveDraft($this->biz(), $this->invoice($id), $this->payload($request));

        return $this->afterSave($request, $invoice);
    }

    private function afterSave(Request $request, Invoice $invoice)
    {
        if ($request->header('X-Autosave') === '1') {
            return response()->json(['ok' => true, 'lock_version' => $invoice->lock_version, 'total' => \App\Support\Money::format($invoice->total_cents)]);
        }
        if ($request->input('next') === 'review') {
            return $this->to('/invoices/'.$invoice->id, 'Draft saved. Review it, then issue.');
        }
        if ($request->input('next') === 'preview') {
            return $this->to('/invoices/'.$invoice->id.'/edit?preview=1', 'Draft saved.');
        }

        return $this->to('/invoices/'.$invoice->id.'/edit', 'Draft saved.');
    }

    public function show(int $id)
    {
        $invoice = $this->invoice($id)->load(['lines', 'taxLines', 'client', 'allocations.payment', 'allocations.creditNote', 'claims', 'documentVersions', 'contractVersion.contract', 'creditNotes']);

        return view('invoices.show', [
            'invoice' => $invoice,
            'outbox' => EmailOutbox::where('business_id', $invoice->business_id)->where('invoice_id', $invoice->id)->latest('id')->get(),
            'links' => PublicToken::where('invoice_id', $invoice->id)->latest('id')->get(),
            'access' => \App\Models\DocumentAccessEvent::where('business_id', $invoice->business_id)->where('invoice_id', $invoice->id)->latest('id')->limit(30)->get(),
            'activity' => \App\Models\AuditEvent::with('actor')->where('business_id', $invoice->business_id)->where('object_type', 'Invoice')->where('object_id', $invoice->id)->latest('id')->get(),
            'incomeAccounts' => ChartAccount::where('business_id', $invoice->business_id)->where('type', 'income')->orderBy('code')->get(),
        ]);
    }

    public function issue(int $id)
    {
        $invoice = $this->service->issue($this->invoice($id));

        return $this->to('/invoices/'.$invoice->id, 'Invoice '.$invoice->number.' issued and posted to the ledger. It has not been emailed yet.');
    }

    public function pdf(Request $request, int $id, InvoicePresenter $presenter, PdfRenderer $pdf, DocumentStore $docs)
    {
        $invoice = $this->invoice($id);
        if ($invoice->isDraft()) {
            $bytes = $pdf->render('pdf.invoice', $presenter->viewModel($invoice) + ['watermark' => 'DRAFT']);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="draft-invoice.pdf"']);
        }
        $v = $invoice->latestDocument() ?? throw new DomainException('PDF not found.');

        return $docs->download($v->document, $invoice->number.'.pdf', $request->boolean('inline'));
    }

    public function documentVersion(int $id, int $version, DocumentStore $docs)
    {
        $invoice = $this->invoice($id);
        $v = InvoiceDocumentVersion::where('business_id', $invoice->business_id)->where('invoice_id', $invoice->id)->where('version', $version)->firstOrFail();

        return $docs->download($v->document, $invoice->number.'-v'.$v->version.'.pdf');
    }

    public function sendForm(int $id)
    {
        $invoice = $this->invoice($id)->load('client.contacts');
        if (! $invoice->isIssued()) return $this->to('/invoices/'.$id)->with('error', 'Issue the invoice before sending.');

        return view('invoices.send', [
            'invoice' => $invoice,
            'recipients' => implode(', ', $invoice->recipients ?: array_filter([$invoice->client->billingEmail()])),
            'previous' => EmailOutbox::where('invoice_id', $invoice->id)->count(),
            'notifyHealth' => app(\App\Services\Notifications\NotificationGateway::class)->health(),
        ]);
    }

    public function send(Request $request, int $id, EmailComposer $composer)
    {
        $invoice = $this->invoice($id);
        $data = $request->validate([
            'recipients' => ['required', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', Rule::in(['invoice', 'reminder'])],
            'confirm_resend' => ['nullable', 'boolean'],
        ]);
        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $data['recipients']))));
        foreach ($emails as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) throw new DomainException('Invalid email: '.$e);
        }
        if (EmailOutbox::where('invoice_id', $invoice->id)->whereIn('status', ['queued', 'sending'])->exists()) {
            throw new DomainException('A send is already in progress for this invoice.');
        }
        $n = $composer->queueInvoice($invoice, $emails, $data['kind'] ?? 'invoice', $data['message'] ?? null);

        return $this->to('/invoices/'.$id, "Queued {$n} email(s). Status updates when the mail service accepts them (usually within a minute).");
    }

    public function bulkSend(Request $request, EmailComposer $composer)
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $sent = 0;
        $skipped = [];
        foreach (Invoice::with('client.contacts')->where('business_id', $this->biz()->id)->whereIn('id', $ids)->get() as $inv) {
            $to = $inv->recipients ?: array_filter([$inv->client->billingEmail()]);
            if (! $inv->isIssued() || ! $to) { $skipped[] = $inv->displayNumber(); continue; }
            $composer->queueInvoice($inv, $to, $request->input('kind') === 'reminder' ? 'reminder' : 'invoice');
            $sent++;
        }

        return $this->to('/invoices', "Queued {$sent} invoice email(s).".($skipped ? ' Skipped (not issued or no email): '.implode(', ', $skipped) : ''));
    }

    public function resendReviewed(Request $request, int $id)
    {
        $row = EmailOutbox::where('business_id', $this->biz()->id)->findOrFail($id);
        if (! in_array($row->status, ['failed', 'delivery_unknown'], true)) throw new DomainException('Only failed or unknown sends can be reviewed.');
        if (! $row->encrypted_body) throw new DomainException('This message was purged. Send the document again instead.');
        $row->update(['status' => 'queued', 'available_at' => now(), 'needs_review' => false, 'attempts' => 0]);
        Audit::log($this->biz(), 'outbox.resend_after_review', $row, null, null, $request->input('reason', 'Reviewed by staff'));

        return back()->with('status', 'Message re-queued after review.');
    }

    public function revokeLinks(int $id)
    {
        $invoice = $this->invoice($id);
        $n = PublicToken::where('invoice_id', $invoice->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Audit::log($this->biz(), 'invoice.links_revoked', $invoice, null, ['count' => $n]);

        return back()->with('status', "Revoked {$n} client link(s). Send the invoice again to issue a new link.");
    }

    public function void(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'reissue' => ['nullable', 'boolean']]);
        $invoice = $this->service->void($this->invoice($id), $data['reason']);
        if ($request->boolean('reissue')) {
            $new = $this->service->duplicateAsDraft($invoice);

            return $this->to('/invoices/'.$new->id.'/edit', 'Invoice voided. Edit the replacement draft, then issue it with a new number.');
        }

        return $this->to('/invoices/'.$invoice->id, 'Invoice voided and its ledger entry reversed.');
    }

    public function destroy(int $id)
    {
        $this->service->deleteDraft($this->invoice($id));

        return $this->to('/invoices', 'Draft deleted.');
    }

    public function duplicate(int $id)
    {
        $new = $this->service->duplicateAsDraft($this->invoice($id));
        $new->replaces_invoice_id = null;
        $new->save();

        return $this->to('/invoices/'.$new->id.'/edit', 'Copied to a new draft.');
    }

    public function creditForm(int $id)
    {
        $invoice = $this->invoice($id)->load('lines');

        return view('invoices.credit', [
            'invoice' => $invoice,
            'incomeAccounts' => ChartAccount::where('business_id', $invoice->business_id)->where('type', 'income')->orderBy('code')->get(),
        ]);
    }

    public function credit(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'issue_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'max:50'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.income_account_id' => ['nullable', 'integer'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
        ]);
        $lines = collect($data['lines'])->filter(fn ($l) => ($l['amount'] ?? 0) > 0)->map(fn ($l) => [
            'description' => $l['description'] ?: 'Credit',
            'amount_cents' => $this->cents($l['amount']),
            'income_account_id' => $l['income_account_id'] ?? null,
            'tax_rate' => (string) ($l['tax_rate'] ?? '0'),
        ])->values()->all();
        $cn = $this->service->credit($this->invoice($id), $lines, $data['reason'], 'credit', $data['issue_date'] ?? null);

        return $this->to('/invoices/'.$id, 'Credit note '.$cn->number.' applied.');
    }

    public function writeOff(Request $request, int $id)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'max:500']]);
        $cn = $this->service->credit($this->invoice($id), [['amount_cents' => $this->cents($data['amount'])]], $data['reason'], 'write_off');

        return $this->to('/invoices/'.$id, 'Written off as bad debt ('.$cn->number.').');
    }
}
