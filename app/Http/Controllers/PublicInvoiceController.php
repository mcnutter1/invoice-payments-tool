<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ContractVersion;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Models\PrivateDocument;
use App\Models\PublicToken;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Invoicing\InvoicePresenter;
use App\Services\Notifications\EmailTracker;
use App\Services\PublicAccess\ClientActivity;
use App\Services\PublicAccess\PublicLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Client invoice pages. Access requires a session grant obtained from a valid invoice link. */
class PublicInvoiceController extends Controller
{
    public function __construct(private readonly PublicLinkService $links) {}

    private function notFound()
    {
        return response()->view('public.not-available', [], 404);
    }

    private function log(PublicToken $t, string $event, Request $request, ?int $versionId = null, ?string $detail = null): void
    {
        app(ClientActivity::class)->record($request, [
            'business_id' => $t->business_id,
            'public_token_id' => $t->id,
            'invoice_id' => $t->invoice_id,
            'contract_version_id' => $versionId,
            'event' => $event,
            'detail' => $detail,
        ], $t->recipient_email);
    }

    /** GET /i/{token}: exchange for a scoped session grant, then redirect to a clean URL. Never changes financial state. */
    public function exchange(Request $request, string $token)
    {
        $t = $this->links->find($token, 'invoice_view');
        if (! $t || ! $t->invoice_id) return $this->notFound();
        $this->links->grant($request, $t);
        $this->log($t, 'link_accessed', $request);
        $action = match ($request->query('d')) {
            'pdf' => 'pdf',
            'paid' => 'paid_pdf',
            default => $request->query('a'),
        };
        if ($request->query('e')) {
            app(EmailTracker::class)->recordClick($request->query('e'), $action, $t, $request);
        }
        $invoice = Invoice::findOrFail($t->invoice_id);
        if ($action === 'pay') {
            $this->log($t, 'pay_clicked', $request, null, 'email');

            return redirect('/i/v/'.$invoice->ulid.'#howtopay');
        }
        if ($action === 'report') {
            return redirect('/i/v/'.$invoice->ulid.'?report=1#report');
        }

        return redirect('/i/v/'.$invoice->ulid.match ($action) {
            'pdf' => '/pdf',
            'paid_pdf' => '/paid-pdf',
            default => '',
        });
    }

    private function resolve(Request $request, string $ulid): array
    {
        $invoice = Invoice::where('ulid', strtolower($ulid))->first();
        if (! $invoice) return [null, null];
        $t = $this->links->granted($request, 'invoice_view', fn ($t) => $t->invoice_id === $invoice->id);
        if (! $t || ! in_array($invoice->status, ['issued', 'void'], true)) return [null, null];

        return [$invoice, $t];
    }

    public function show(Request $request, string $ulid, InvoicePresenter $presenter)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice) return $this->notFound();
        $this->log($t, 'invoice_viewed', $request);
        $vm = $presenter->viewModel($invoice);
        $claims = PaymentClaim::where('invoice_id', $invoice->id)->latest()->get();
        $business = Business::find($invoice->business_id);
        $agreement = $invoice->contract_version_id && $t->allows('view_contract')
            ? ContractVersion::where('business_id', $invoice->business_id)->where('id', $invoice->contract_version_id)->where('status', 'completed')->first()
            : null;
        $suppressed = $claims->where('status', 'reported')->first(fn ($c) => $c->created_at->gt(now()->subDays($business->claim_review_days)));

        return view('public.invoice', $vm + [
            'claims' => $claims,
            'openClaim' => $claims->firstWhere('status', 'reported'),
            'agreement' => $agreement,
            'agreementTitle' => $invoice->contractVersion?->title ?? $invoice->contract?->title,
            'canReport' => $t->allows('report_payment') && $invoice->status === 'issued' && $invoice->balance_cents > 0,
            'reviewing' => (bool) $suppressed,
            'openReport' => $request->boolean('report'),
            'w9Available' => $invoice->status === 'issued' && ($w9 = app(\App\Services\Tax\W9Service::class))->settings($business)['allow_client_requests'] && $w9->ready($business),
        ]);
    }

    /** "Pay now" on the invoice page: tracked, then jumps to the payment instructions. */
    public function pay(Request $request, string $ulid)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice) return $this->notFound();
        $this->log($t, 'pay_clicked', $request, null, 'page');

        return redirect('/i/v/'.$invoice->ulid.'#howtopay');
    }

    /** Lightweight client-side signals (e.g. copying Zelle details). Never changes financial state. */
    public function event(Request $request, string $ulid)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice) return response()->noContent(404);
        $data = $request->validate([
            'event' => ['required', Rule::in(['payment_details_copied'])],
            'detail' => ['nullable', Rule::in(['zelle_email', 'zelle_mobile', 'amount', 'memo'])],
        ]);
        $this->log($t, $data['event'], $request, null, $data['detail'] ?? null);

        return response()->noContent();
    }

    public function pdf(Request $request, string $ulid, DocumentStore $docs)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice || ! $t->allows('pdf')) return $this->notFound();
        $v = $invoice->latestDocument();
        if (! $v) return $this->notFound();
        $this->log($t, 'pdf_downloaded', $request);

        return $docs->download($v->document, $invoice->number.'.pdf');
    }

    /** Current copy of the invoice (payments applied), stamped PAID once the balance is cleared. */
    public function paidPdf(Request $request, string $ulid, PdfRenderer $pdf, InvoicePresenter $presenter)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice || ! $t->allows('pdf') || $invoice->status !== 'issued') return $this->notFound();
        $this->log($t, 'paid_pdf_downloaded', $request);
        $paid = $invoice->balance_cents <= 0;
        $vm = $presenter->viewModel($invoice) + ($paid ? ['watermark' => 'PAID', 'watermarkClass' => 'watermark-paid'] : []);

        return response($pdf->render('pdf.invoice', $vm), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.($paid ? '-paid' : '').'.pdf"',
        ]);
    }

    public function remittance(Request $request, string $ulid, PdfRenderer $pdf, InvoicePresenter $presenter)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice || empty($invoice->instructions_snapshot['check'])) return $this->notFound();
        $this->log($t, 'remittance_downloaded', $request);

        return response($pdf->render('pdf.remittance', $presenter->viewModel($invoice)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="remittance-'.$invoice->number.'.pdf"',
        ]);
    }

    private function agreementDoc(Request $request, string $ulid, string $kind, DocumentStore $docs)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice || ! $invoice->contract_version_id || ! $t->allows('view_contract')) return $this->notFound();
        $v = ContractVersion::where('business_id', $invoice->business_id)->where('id', $invoice->contract_version_id)->where('status', 'completed')->first();
        $docId = $v?->{$kind === 'certificate' ? 'certificate_document_id' : 'signed_document_id'};
        if (! $docId) return $this->notFound();
        $this->log($t, 'contract_viewed', $request, $v->id);

        return $docs->download(PrivateDocument::where('business_id', $invoice->business_id)->findOrFail($docId), 'agreement-'.$kind.'.pdf');
    }

    public function agreement(Request $request, string $ulid, DocumentStore $docs)
    {
        return $this->agreementDoc($request, $ulid, 'signed', $docs);
    }

    public function agreementCertificate(Request $request, string $ulid, DocumentStore $docs)
    {
        return $this->agreementDoc($request, $ulid, 'certificate', $docs);
    }

    public function reportPayment(Request $request, string $ulid, DocumentStore $docs)
    {
        [$invoice, $t] = $this->resolve($request, $ulid);
        if (! $invoice || ! $t->allows('report_payment') || $invoice->status !== 'issued') return $this->notFound();
        $methods = $invoice->instructions_snapshot['methods'] ?? [];
        $data = $request->validate([
            'method' => ['required', Rule::in($methods)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reported_date' => ['required', 'date', 'before_or_equal:tomorrow', 'after:-1 year'],
            'reference' => ['nullable', 'string', 'max:120'],
            'check_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]*$/'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'max:5120'],
        ]);
        if (PaymentClaim::where('invoice_id', $invoice->id)->where('created_at', '>', now()->subDay())->count() >= 5) {
            throw new DomainException('Too many payment reports for this invoice today. Please contact the business directly.');
        }
        $business = Business::findOrFail($invoice->business_id);
        $docId = $request->hasFile('receipt') ? $docs->storeUpload($business, $request->file('receipt'), 'claim_receipt')->id : null;
        $claim = new PaymentClaim([
            'invoice_id' => $invoice->id,
            'method' => $data['method'],
            'reported_amount_cents' => $this->cents($data['amount']),
            'reported_date' => $data['reported_date'],
            'reference' => $data['reference'] ?? null,
            'check_number' => $data['check_number'] ?? null,
            'note' => $data['note'] ?? null,
            'attachment_document_id' => $docId,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        $claim->business_id = $business->id;
        $claim->save();
        $this->log($t, 'payment_reported', $request);
        Audit::log($business, 'claim.submitted', $claim, null, ['invoice' => $invoice->number, 'method' => $claim->method, 'amount_cents' => $claim->reported_amount_cents], null, 'public');

        return redirect('/i/v/'.$invoice->ulid)->with('status', 'Thank you. Your payment report was sent to '.$business->name().' and is awaiting confirmation.');
    }
}
