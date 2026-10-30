<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\ContractRecipient;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\Membership;
use App\Models\PrivateDocument;
use App\Services\Access\PermissionService;
use App\Services\Audit;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\SignatureService;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Invoicing\InvoiceService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function __construct(private readonly ContractService $service) {}

    private function contract(int $id): Contract
    {
        return Contract::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $q = Contract::with('client')->where('business_id', $this->biz()->id);
        if ($s = $request->query('status')) $q->where('status', $s);

        return view('contracts.index', ['contracts' => $q->latest('id')->paginate(25)->withQueryString()]);
    }

    private function formData(?Contract $contract = null, ?ContractVersion $version = null): array
    {
        $b = $this->biz();
        $perms = app(PermissionService::class);
        $countersigners = Membership::with('user')->where('business_id', $b->id)->where('status', 'active')->get()
            ->filter(fn ($m) => in_array('contracts.countersign', $perms->permissionsFor($m), true))->pluck('user');

        return [
            'contract' => $contract,
            'version' => $version,
            'clients' => Client::with('contacts')->where('business_id', $b->id)->where('status', 'active')->orderBy('display_name')->get(),
            'templates' => ContractTemplate::where('business_id', $b->id)->where('active', true)->get(),
            'countersigners' => $countersigners,
            'fields' => ContractService::MERGE_FIELDS,
            'milestones' => $contract ? ContractMilestone::where('contract_id', $contract->id)->get() : collect(),
        ];
    }

    public function create(Request $request)
    {
        return view('contracts.form', $this->formData() + ['selectedClient' => (int) $request->query('client') ?: null]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $b = $this->biz();
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'template_id' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:100000'],
            'effective_date' => ['nullable', 'date'],
            'term_start_date' => ['nullable', 'date'],
            'term_end_date' => ['nullable', 'date'],
            'term_open_ended' => ['nullable', 'boolean'],
            'scope' => ['nullable', 'string', 'max:20000'],
            'fees' => ['nullable', 'string', 'max:10000'],
            'recurring_fee' => ['nullable', 'string', 'max:2000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'committed_value' => ['nullable', 'numeric', 'min:0'],
            'value_cap' => ['nullable', 'numeric', 'min:0'],
            'cap_behavior' => ['nullable', Rule::in(['warn', 'block'])],
            'signing_order' => ['required', Rule::in(['parallel', 'sequential'])],
            'require_email_code' => ['nullable', 'boolean'],
            'signers' => ['nullable', 'array', 'max:10'],
            'signers.*.name' => ['nullable', 'string', 'max:120'],
            'signers.*.email' => ['nullable', 'email'],
            'signers.*.title' => ['nullable', 'string', 'max:120'],
            'countersigner_user_id' => ['nullable', 'integer'],
            'countersigner_title' => ['nullable', 'string', 'max:120'],
            'milestones' => ['nullable', 'array', 'max:50'],
            'milestones.*.name' => ['nullable', 'string', 'max:200'],
            'milestones.*.amount' => ['nullable', 'numeric', 'min:0'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.description' => ['nullable', 'string', 'max:1000'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'monthly_fee_description' => ['nullable', 'string', 'max:200'],
            'billing_start_date' => ['nullable', 'date'],
            'billing_auto' => ['nullable', 'boolean'],
            'billing_mode' => ['nullable', Rule::in(array_keys(\App\Services\Contracts\ContractBillingService::MODES))],
        ];
        if ($creating) $rules['client_id'] = ['required', Rule::exists('clients', 'id')->where('business_id', $b->id)];
        else $rules['client_id'] = ['nullable', Rule::exists('clients', 'id')->where('business_id', $b->id)];

        $data = $request->validate($rules);
        $data['term_open_ended'] = $request->boolean('term_open_ended');
        $data['billing_auto'] = $request->boolean('billing_auto');
        if ($data['term_open_ended']) $data['term_end_date'] = null;
        $termStart = $data['term_start_date'] ?? $data['effective_date'] ?? null;
        if (! empty($data['term_end_date']) && $termStart && $data['term_end_date'] < $termStart) {
            throw \Illuminate\Validation\ValidationException::withMessages(['term_end_date' => 'The term end date must be on or after the term begin date.']);
        }
        if (! empty($data['countersigner_user_id'])) {
            $ok = Membership::where('business_id', $b->id)->where('user_id', $data['countersigner_user_id'])->where('status', 'active')->exists();
            if (! $ok) throw new DomainException('Countersigner must be an active member of this business.');
        }

        return $data;
    }

    public function store(Request $request)
    {
        $c = $this->service->create($this->biz(), $this->validated($request, true));

        return $this->to('/contracts/'.$c->id, 'Draft agreement created. Review the preview, then prepare and send.');
    }

    public function show(int $id)
    {
        $c = $this->contract($id)->load(['client', 'versions.recipients', 'milestones', 'invoices', 'schedules']);
        $current = $c->currentVersion()->with('recipients')->first();
        $me = $this->ctx()->user;

        return view('contracts.show', [
            'contract' => $c,
            'current' => $current,
            'events' => $current ? $current->events()->get() : collect(),
            'preview' => $current ? ContractService::toHtml($current->snapshot['rendered'] ?? '') : '',
            'missing' => $current && ! $current->source_document_id ? $this->service->render($current->body ?? '', $current->snapshot['merge'] ?? [])[1] : [],
            'invoiced' => $c->invoicedCents(),
            'collected' => $c->collectedCents(),
            'myCountersign' => $current ? $current->recipients->first(fn ($r) => $r->countersigner_user_id === $me->id && SignatureService::canCountersignNow($current, $r)) : null,
            'deleteBlocker' => $this->service->deleteBlocker($c),
            'renewals' => Contract::where('business_id', $c->business_id)->where('renewed_from_id', $c->id)->get(['id', 'number', 'status']),
        ]);
    }

    public function edit(int $id)
    {
        $c = $this->contract($id);
        $v = $c->currentVersion;
        if (! $v || $v->isLocked()) return $this->to('/contracts/'.$id)->with('error', 'This version was sent and cannot be edited. Void it to create a new version.');

        return view('contracts.form', $this->formData($c, $v->load('recipients')) + ['selectedClient' => $c->client_id, 'clientBlocker' => $this->service->clientChangeBlocker($c)]);
    }

    public function update(Request $request, int $id)
    {
        $c = $this->contract($id);
        $v = $c->currentVersion ?? throw new DomainException('No version.');
        $data = $this->validated($request, false);
        \Illuminate\Support\Facades\DB::transaction(function () use ($c, $v, $data) {
            if (! empty($data['client_id']) && (int) $data['client_id'] !== (int) $c->client_id) {
                if ($blocker = $this->service->clientChangeBlocker($c)) throw new DomainException('The client can’t be changed: '.$blocker);
                $from = $c->client_id;
                $c->update(['client_id' => (int) $data['client_id']]);
                Audit::log($this->biz(), 'contract.client_changed', $c, ['client_id' => $from], ['client_id' => $c->client_id]);
            }
            $this->service->writeVersion($this->biz(), $c, $v, $data);
        });
        Audit::log($this->biz(), 'contract.draft_updated', $v);

        return $this->to('/contracts/'.$id, 'Draft saved.');
    }

    public function prepare(int $id)
    {
        $c = $this->contract($id);
        $this->service->prepare($c->currentVersion ?? throw new DomainException('No version.'));

        return $this->to('/contracts/'.$id, 'Locked and rendered. Review the PDF, then send for signature.');
    }

    public function send(int $id)
    {
        $c = $this->contract($id);
        $this->service->send($c->currentVersion ?? throw new DomainException('No version.'));

        return $this->to('/contracts/'.$id, 'Sent for signature.');
    }

    public function void(Request $request, int $id)
    {
        $c = $this->contract($id);
        $this->service->void($c->currentVersion ?? throw new DomainException('No version.'), $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return $this->to('/contracts/'.$id, 'Envelope voided. Create a new version to make changes.');
    }

    public function newVersion(int $id)
    {
        $this->service->newVersion($this->contract($id), false);

        return $this->to('/contracts/'.$id.'/edit', 'New draft version created.');
    }

    public function amend(int $id)
    {
        $this->service->newVersion($this->contract($id), true);

        return $this->to('/contracts/'.$id.'/edit', 'Amendment draft created. The signed original is unchanged.');
    }

    public function remind(int $id)
    {
        $r = ContractRecipient::where('business_id', $this->biz()->id)->findOrFail($id);
        $this->service->remind($r);

        return back()->with('status', 'Reminder queued.');
    }

    public function uploadExternal(Request $request, int $id)
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('finance.storage.max_upload_kb')], 'provenance' => ['required', 'string', 'max:1000']]);
        $this->service->uploadExternallySigned($this->contract($id), $request->file('file'), $data['provenance']);

        return $this->to('/contracts/'.$id, 'Externally signed agreement uploaded and labeled as such.');
    }

    public function terminate(Request $request, int $id)
    {
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:500']]);
        $this->service->terminate($this->contract($id), $data['date'], $data['reason']);

        return $this->to('/contracts/'.$id, 'Agreement terminated. Linked recurring billing was paused.');
    }

    public function reactivate(Request $request, int $id)
    {
        $data = $request->validate(['term_end_date' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:500']]);
        $this->service->reactivate($this->contract($id), $data['term_end_date'] ?? null, $data['reason']);

        return $this->to('/contracts/'.$id, 'Agreement reactivated. Paused contract billing was resumed.');
    }

    public function renew(int $id)
    {
        $new = $this->service->renew($this->contract($id));

        return $this->to('/contracts/'.$new->id.'/edit', 'Renewal draft '.$new->number.' created for the next term. Review the dates and terms, then lock and send.');
    }

    public function destroy(Request $request, int $id)
    {
        $c = $this->contract($id);
        $data = $request->validate(['confirm' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:500']]);
        if (strcasecmp(trim($data['confirm']), $c->number) !== 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['confirm' => 'Type '.$c->number.' to confirm.']);
        }
        $this->service->delete($c, ($data['reason'] ?? null) ?: 'Deleted by user');

        return $this->to('/contracts', 'Agreement '.$c->number.' was permanently deleted.');
    }

    public function billingOverride(Request $request, int $id)
    {
        $c = $this->contract($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $c->update(['billing_override_reason' => $reason]);
        Audit::log($this->biz(), 'contract.billing_override', $c, null, null, $reason);

        return back()->with('status', 'Billing override recorded.');
    }

    public function preview(int $id, int $version)
    {
        $c = $this->contract($id)->load('client');
        $v = ContractVersion::where('business_id', $c->business_id)->where('contract_id', $c->id)->where('version', $version)->with('recipients')->firstOrFail();
        $business = $this->biz();

        return view('contracts.preview', [
            'contract' => $c,
            'version' => $v,
            'business' => $business,
            'html' => $v->source_document_id ? null : ContractService::toHtml($v->snapshot['rendered'] ?? ''),
            'logo' => app(DocumentStore::class)->dataUri($business->logo),
            'accent' => $business->brand('accent'),
        ]);
    }

    public function download(int $id, int $version, string $kind, DocumentStore $docs, PdfRenderer $pdf)
    {
        $c = $this->contract($id);
        $v = ContractVersion::where('business_id', $c->business_id)->where('contract_id', $c->id)->where('version', $version)->firstOrFail();
        if ($kind === 'document' && ! $v->rendered_document_id) {
            // Draft preview, not the immutable artifact.
            return response($pdf->render('pdf.contract', $this->service->documentModel($v, $c) + ['watermark' => 'DRAFT']), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="draft.pdf"',
            ]);
        }
        $docId = match ($kind) {
            'document' => $v->rendered_document_id,
            'signed' => $v->signed_document_id,
            'certificate' => $v->certificate_document_id,
        };
        abort_unless($docId, 404);

        return $docs->download(PrivateDocument::where('business_id', $c->business_id)->findOrFail($docId), $c->number.'-v'.$v->version.'-'.$kind.'.pdf', true);
    }

    public function invoiceMilestone(int $id, int $milestone, \App\Services\Contracts\ContractBillingService $billing)
    {
        $c = $this->contract($id);
        $m = ContractMilestone::where('business_id', $c->business_id)->where('contract_id', $c->id)->findOrFail($milestone);
        if ($m->invoice_id) throw new DomainException('This milestone was already invoiced.');
        if (! $c->isActiveForBilling() ) throw new DomainException('The agreement must be signed (or have a recorded billing override) before billing.');
        $versionId = $c->currentVersion?->status === 'completed' ? $c->current_version_id : ($c->versions()->where('status', 'completed')->value('id') ?? $c->current_version_id);
        $invoice = $billing->milestoneInvoice($c, $m, $versionId);

        return $invoice->isDraft()
            ? $this->to('/invoices/'.$invoice->id.'/edit', 'Draft invoice created for the milestone.')
            : $this->to('/invoices/'.$invoice->id, 'Milestone invoice issued.');
    }

    public function runBilling(\App\Services\Contracts\ContractBillingService $billing, int $id)
    {
        $c = $this->contract($id);
        if (! $c->isExecuted()) throw new DomainException('Invoices are created once the agreement is fully signed.');
        if (! $c->billing_auto) $c->update(['billing_auto' => true]);
        $out = $billing->onExecuted($c);
        Audit::log($this->biz(), 'contract.billing_run', $c, null, $out);

        return $this->to('/contracts/'.$id, $out['milestones'] || $out['schedule']
            ? 'Billing set up: '.$out['milestones'].' milestone invoice(s)'.($out['schedule'] ? ' and the monthly schedule.' : '.')
            : 'Billing is already up to date.');
    }

    public function countersignForm(int $id)
    {
        $c = $this->contract($id);
        $v = $c->currentVersion;
        $r = $v?->recipients()->where('role', 'countersigner')->where('countersigner_user_id', $this->ctx()->user->id)->first();
        abort_unless($r && SignatureService::canCountersignNow($v, $r), 404);

        return view('contracts.countersign', ['contract' => $c, 'version' => $v, 'recipient' => $r, 'consent' => SignatureService::CONSENT_TEXT,
            'html' => $v->source_document_id ? null : ContractService::toHtml($v->snapshot['rendered'] ?? ''),
            'savedSignature' => $this->savedSignatureDoc() ? app(DocumentStore::class)->dataUri($this->savedSignatureDoc()) : null]);
    }

    private function myMembership(): Membership
    {
        return Membership::where('business_id', $this->biz()->id)->where('user_id', $this->ctx()->user->id)->where('status', 'active')->firstOrFail();
    }

    private function savedSignatureDoc(): ?PrivateDocument
    {
        $id = $this->myMembership()->signature_document_id;

        return $id ? PrivateDocument::where('business_id', $this->biz()->id)->where('mime', 'image/png')->find($id) : null;
    }

    public function countersign(Request $request, int $id, SignatureService $sig, DocumentStore $docs)
    {
        $c = $this->contract($id);
        $v = $c->currentVersion;
        $r = $v?->recipients()->where('role', 'countersigner')->where('countersigner_user_id', $this->ctx()->user->id)->first();
        abort_unless($r, 404);
        $data = $request->validate([
            'typed_name' => ['required', 'string', 'max:120'], 'typed_title' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'], 'authority' => ['accepted'], 'signature_kind' => ['required', Rule::in(['type', 'draw', 'saved'])],
            'signature_data' => ['nullable', 'string', 'max:400000'], 'document_sha256' => ['required', 'string', 'size:64'],
            'save_signature' => ['nullable', 'boolean'],
        ]);
        if ($data['signature_kind'] === 'saved') {
            $saved = $this->savedSignatureDoc() ?? throw new DomainException('No saved signature found. Draw or type your signature.');
            $data['signature_kind'] = 'draw';
            $data['signature_data'] = 'data:image/png;base64,'.base64_encode($saved->contents());
        }
        $sig->sign($v, $r, $data + ['consent' => true, 'authority' => true], 'authenticated_session');
        if ($request->boolean('save_signature') && $request->input('signature_kind') === 'draw'
            && preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string) $data['signature_data'], $m)) {
            $doc = $docs->put($this->biz(), 'saved_signature', 'my-signature.png', base64_decode($m[1]), 'image/png');
            $this->myMembership()->update(['signature_document_id' => $doc->id]);
            Audit::log($this->biz(), 'member.signature_saved', $this->myMembership());
        }

        return $this->to('/contracts/'.$id, $v->fresh()->status === 'prepared' ? 'Signed. Send it for signature when you’re ready — the client will see your signature is already in place.' : 'Countersigned.');
    }

    public function forgetSignature()
    {
        $this->myMembership()->update(['signature_document_id' => null]);
        Audit::log($this->biz(), 'member.signature_removed', $this->myMembership());

        return back()->with('status', 'Saved signature removed.');
    }

    public function mySignature(DocumentStore $docs)
    {
        return view('contracts.my-signature', ['savedSignature' => ($d = $this->savedSignatureDoc()) ? $docs->dataUri($d) : null]);
    }

    public function uploadSignature(Request $request, DocumentStore $docs)
    {
        $request->validate(['signature' => ['required', 'file', 'max:2048', 'mimetypes:image/png']]);
        $bytes = (string) file_get_contents($request->file('signature')->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if (! str_starts_with($bytes, "\x89PNG") || ! $info || $info[2] !== IMAGETYPE_PNG || $info[0] > 4000 || $info[1] > 4000) {
            throw new DomainException('Upload a PNG image of your signature (up to 4000×4000 pixels).');
        }
        // Re-encode: strips metadata and fits the signature line (max 600×200), keeping transparency.
        $src = @imagecreatefromstring($bytes) ?: throw new DomainException('That PNG could not be read.');
        $scale = min(1, 600 / imagesx($src), 200 / imagesy($src));
        $w = max(1, (int) round(imagesx($src) * $scale));
        $h = max(1, (int) round(imagesy($src) * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();

        $doc = $docs->put($this->biz(), 'saved_signature', 'my-signature.png', $png, 'image/png');
        $this->myMembership()->update(['signature_document_id' => $doc->id]);
        Audit::log($this->biz(), 'member.signature_saved', $this->myMembership(), null, ['source' => 'upload']);

        return back()->with('status', 'Signature saved. It’s selected automatically when you countersign.');
    }

    public function templates()
    {
        return view('contracts.templates', ['templates' => ContractTemplate::where('business_id', $this->biz()->id)->get(), 'fields' => ContractService::MERGE_FIELDS]);
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:100000']]);
        $data['body'] = $this->templateBody($data['body']);
        $t = ContractTemplate::create($data + ['business_id' => $this->biz()->id, 'created_by' => $this->ctx()->user->id]);
        Audit::log($this->biz(), 'contract_template.created', $t);

        return back()->with('status', 'Template saved.');
    }

    public function updateTemplate(Request $request, int $id)
    {
        $t = ContractTemplate::where('business_id', $this->biz()->id)->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:100000'], 'active' => ['nullable', 'boolean']]);
        $t->update(['name' => $data['name'], 'body' => $this->templateBody($data['body']), 'active' => $request->boolean('active'), 'version' => $t->version + 1]);
        Audit::log($this->biz(), 'contract_template.updated', $t);

        return back()->with('status', 'Template saved as version '.$t->version.'. Existing agreements are unchanged.');
    }

    private function templateBody(string $body): string
    {
        $html = \App\Support\RichText::normalize($body);
        if ($html === '') throw new DomainException('The template text is empty.');
        $this->validateFields($html);

        return $html;
    }

    private function validateFields(string $body): void
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', $body, $m);
        $unknown = array_diff(array_unique($m[1]), ContractService::MERGE_FIELDS);
        if ($unknown) throw new DomainException('Unknown merge fields: '.implode(', ', $unknown));
    }
}
