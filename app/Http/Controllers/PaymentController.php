<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentClaim;
use App\Services\Documents\DocumentStore;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    private function payment(int $id): Payment
    {
        return Payment::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    private function claimModel(int $id): PaymentClaim
    {
        return PaymentClaim::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    private function depositAccounts()
    {
        return ChartAccount::where('business_id', $this->biz()->id)->whereIn('subtype', ['bank', 'undeposited'])->where('is_active', true)->orderBy('code')->get();
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        $q = Payment::with('client')->where('business_id', $b->id);
        if ($s = $request->query('status')) $q->where('status', $s);
        if ($m = $request->query('method')) $q->where('method', $m);

        return view('payments.index', [
            'payments' => $q->orderByDesc('received_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'claims' => PaymentClaim::with('invoice.client')->where('business_id', $b->id)->whereIn('status', ['reported', 'clarification_requested'])->orderBy('created_at')->get(),
            'undeposited' => Payment::with('client')->where('business_id', $b->id)->where('status', 'recorded')->where('deposit_account_id', $b->account('undeposited')->id)->get(),
            'banks' => ChartAccount::where('business_id', $b->id)->where('subtype', 'bank')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $b = $this->biz();
        $clientId = (int) $request->query('client');
        $invoiceId = (int) $request->query('invoice');
        if ($invoiceId) $clientId = Invoice::where('business_id', $b->id)->findOrFail($invoiceId)->client_id;

        return view('payments.create', [
            'clients' => Client::where('business_id', $b->id)->orderBy('display_name')->get(['id', 'display_name']),
            'clientId' => $clientId ?: null,
            'invoiceId' => $invoiceId ?: null,
            'openInvoices' => $clientId ? Invoice::where('business_id', $b->id)->where('client_id', $clientId)->where('status', 'issued')->where('balance_cents', '>', 0)->orderBy('due_date')->get() : collect(),
            'accounts' => $this->depositAccounts(),
        ]);
    }

    public function store(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id')->where('business_id', $b->id)],
            'method' => ['required', Rule::in(array_keys(PaymentService::METHODS))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'received_date' => ['required', 'date'],
            'deposit_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'check_number' => ['nullable', 'string', 'max:40'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'alloc' => ['nullable', 'array'],
            'alloc.*' => ['nullable', 'numeric', 'min:0'],
            'send_receipt' => ['nullable', 'boolean'],
        ]);
        $alloc = [];
        foreach ($data['alloc'] ?? [] as $invoiceId => $amt) {
            if ($amt !== null && $amt !== '' && (float) $amt > 0) $alloc[(int) $invoiceId] = $this->cents($amt);
        }
        $payment = $this->service->record($b, [
            'client_id' => $data['client_id'], 'method' => $data['method'], 'amount_cents' => $this->cents($data['amount']),
            'received_date' => $data['received_date'], 'deposit_account_id' => $data['deposit_account_id'] ?? null,
            'reference' => $data['reference'] ?? null, 'check_number' => $data['check_number'] ?? null, 'memo' => $data['memo'] ?? null,
            'send_receipt' => $request->boolean('send_receipt'),
        ], $alloc);

        return $this->to('/payments/'.$payment->id, 'Payment recorded.');
    }

    public function show(int $id)
    {
        $p = $this->payment($id)->load(['client', 'allocations.invoice', 'refunds', 'depositAccount', 'claim', 'recorder']);

        return view('payments.show', [
            'payment' => $p,
            'openInvoices' => Invoice::where('business_id', $p->business_id)->where('client_id', $p->client_id)->where('status', 'issued')->where('balance_cents', '>', 0)->get(),
            'banks' => ChartAccount::where('business_id', $p->business_id)->where('subtype', 'bank')->get(),
            'activity' => \App\Models\AuditEvent::with('actor')->where('business_id', $p->business_id)->where('object_type', 'Payment')->where('object_id', $p->id)->latest('id')->get(),
        ]);
    }

    public function apply(Request $request, int $id)
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'min:0.01'], 'date' => ['nullable', 'date']]);
        $p = $this->payment($id);
        $inv = Invoice::where('business_id', $p->business_id)->findOrFail($data['invoice_id']);
        $this->service->apply($p, $inv, $this->cents($data['amount']), $data['date'] ?? null);

        return back()->with('status', 'Credit applied.');
    }

    public function unapply(Request $request, int $id)
    {
        $alloc = PaymentAllocation::where('business_id', $this->biz()->id)->findOrFail($id);
        $this->service->unapply($alloc, $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return back()->with('status', 'Allocation moved back to unapplied credit.');
    }

    public function deposit(Request $request, int $id)
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer'], 'date' => ['required', 'date']]);
        $bank = ChartAccount::where('business_id', $this->biz()->id)->findOrFail($data['bank_account_id']);
        $this->service->deposit($this->payment($id), $bank, $data['date']);

        return back()->with('status', 'Deposit recorded.');
    }

    public function clear(Request $request, int $id)
    {
        $this->service->markCleared($this->payment($id), $request->validate(['date' => ['required', 'date']])['date']);

        return back()->with('status', 'Marked cleared.');
    }

    public function reverse(Request $request, int $id)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['returned', 'error'])], 'reason' => ['required', 'string', 'max:500'], 'date' => ['nullable', 'date']]);
        $this->service->reverse($this->payment($id), $data['kind'], $data['reason'], $data['date'] ?? null);

        return back()->with('status', 'Payment reversed. Affected invoices are open again.');
    }

    public function refund(Request $request, int $id)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'account_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120']]);
        $this->service->refund($this->payment($id), $this->cents($data['amount']), (int) $data['account_id'], $data['date'], $data['reference'] ?? null);

        return back()->with('status', 'Refund recorded.');
    }

    public function claim(int $id)
    {
        $claim = $this->claimModel($id)->load('invoice.client', 'reviewer', 'payment');

        return view('payments.claim', ['claim' => $claim, 'accounts' => $this->depositAccounts(),
            'defaultAccount' => $this->service->defaultDepositAccount($this->biz(), $claim->method)]);
    }

    public function claimAttachment(int $id, DocumentStore $docs)
    {
        $claim = $this->claimModel($id);
        abort_unless($claim->attachment_document_id, 404);

        return $docs->download($claim->attachment, 'claim-'.$claim->id.'-receipt');
    }

    public function confirmClaim(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'], 'received_date' => ['required', 'date'],
            'deposit_account_id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'], 'evidence_checked' => ['accepted'],
        ], ['evidence_checked.accepted' => 'Confirm you verified the deposit in your bank account or have the physical check.']);
        $payment = $this->service->confirmClaim($this->claimModel($id), [
            'amount_cents' => $this->cents($data['amount']), 'received_date' => $data['received_date'],
            'deposit_account_id' => $data['deposit_account_id'] ?? null, 'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null, 'send_receipt' => $request->boolean('send_receipt', true),
        ]);

        return $this->to('/payments/'.$payment->id, 'Payment confirmed and recorded.');
    }

    public function rejectClaim(Request $request, int $id)
    {
        $this->service->reviewClaim($this->claimModel($id), 'rejected', $request->validate(['note' => ['required', 'string', 'max:1000']])['note']);

        return $this->to('/payments', 'Claim rejected.');
    }

    public function clarifyClaim(Request $request, int $id)
    {
        $this->service->reviewClaim($this->claimModel($id), 'clarification_requested', $request->validate(['note' => ['required', 'string', 'max:1000']])['note']);

        return back()->with('status', 'Marked as needing clarification. Contact the client with your question.');
    }
}
