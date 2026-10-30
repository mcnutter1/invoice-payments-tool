<?php

namespace App\Services\Contracts;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\ContractRecipient;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\PublicToken;
use App\Models\RecurringSchedule;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use App\Services\PublicAccess\PublicLinkService;
use App\Support\Context;
use App\Support\Money;
use App\Support\RichText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly DocumentStore $docs,
        private readonly PdfRenderer $pdf,
        private readonly PublicLinkService $links,
        private readonly EmailComposer $email,
        private readonly Context $ctx,
    ) {}

    public const MERGE_FIELDS = [
        'business.legal_name', 'business.name', 'business.address', 'business.email', 'business.phone',
        'client.legal_name', 'client.name', 'client.address', 'client.email',
        'agreement.title', 'agreement.number', 'agreement.effective_date', 'agreement.term_end_date',
        'agreement.scope', 'agreement.fees', 'agreement.payment_terms_days', 'agreement.committed_value',
        'agreement.milestones', 'agreement.recurring_fee',
    ];

    public function mergeData(Business $b, Client $c, Contract $contract, array $input): array
    {
        $milestones = collect($input['milestones'] ?? [])->filter(fn ($m) => ! empty($m['name']))
            ->map(fn ($m) => '- '.$m['name'].': '.Money::format(Money::parse($m['amount'] ?? 0)).(! empty($m['due_date']) ? ' (due '.$m['due_date'].')' : ''))->implode("\n");

        return [
            'business.legal_name' => $b->legal_name,
            'business.name' => $b->name(),
            'business.address' => implode(', ', $b->addressLines()),
            'business.email' => (string) $b->public_email,
            'business.phone' => (string) $b->phone,
            'client.legal_name' => $c->legal_name ?: $c->display_name,
            'client.name' => $c->display_name,
            'client.address' => implode(', ', $c->billingAddressLines()),
            'client.email' => (string) $c->email,
            'agreement.title' => $contract->title,
            'agreement.number' => $contract->number,
            'agreement.effective_date' => $contract->effective_date?->format('F j, Y') ?? '',
            'agreement.term_end_date' => $contract->term_end_date?->format('F j, Y') ?? 'terminated by either party',
            'agreement.scope' => trim((string) ($input['scope'] ?? '')),
            'agreement.fees' => trim((string) ($input['fees'] ?? '')),
            'agreement.payment_terms_days' => (string) ($input['payment_terms_days'] ?? $b->default_terms_days),
            'agreement.committed_value' => Money::format($contract->committed_value_cents),
            'agreement.milestones' => $milestones,
            'agreement.recurring_fee' => trim((string) ($input['recurring_fee'] ?? '')),
        ];
    }

    /** @return array{0:string,1:list<string>} [body, unresolved fields] */
    public function render(string $template, array $data): array
    {
        $missing = [];
        $html = RichText::isHtml($template);
        $body = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', function ($m) use ($data, &$missing, $html) {
            $v = $data[$m[1]] ?? null;
            if ($v === null || $v === '') {
                $missing[] = $m[1];

                return '[[missing: '.$m[1].']]';
            }

            return $html ? nl2br(e($v), false) : $v;
        }, $template);

        return [$body, array_values(array_unique($missing))];
    }

    public static function toHtml(string $body): string
    {
        return RichText::toHtml($body);
    }

    public function create(Business $business, array $data): Contract
    {
        return DB::transaction(function () use ($business, $data) {
            $client = Client::where('business_id', $business->id)->findOrFail($data['client_id']);
            $contract = new Contract([
                'number' => $this->invoices->nextNumber($business, 'contract', $business->contract_prefix),
                'client_id' => $client->id,
                'title' => $data['title'],
                'status' => 'draft',
                'signing_order' => ($data['signing_order'] ?? 'parallel') === 'sequential' ? 'sequential' : 'parallel',
                'created_by' => $this->ctx->user?->id,
            ]);
            $contract->business_id = $business->id;
            $contract->save();
            $version = $this->writeVersion($business, $contract, null, $data);
            Audit::log($business, 'contract.created', $contract);

            return $contract->fresh();
        });
    }

    /** Why a contract's client can no longer be changed, or null if it can. */
    public function clientChangeBlocker(Contract $c): ?string
    {
        if ($c->versions()->whereNotIn('status', ['draft', 'prepared'])->exists()) return 'a version was already sent for signature. Create a new agreement instead.';
        if ($c->invoices()->exists()) return 'invoices are linked to this agreement.';
        if ($c->schedules()->exists()) return 'recurring billing is linked to this agreement.';

        return null;
    }

    public function writeVersion(Business $business, Contract $contract, ?ContractVersion $version, array $data, ?ContractVersion $amends = null): ContractVersion
    {
        $contract->fill([
            'title' => $data['title'] ?? $contract->title,
            'effective_date' => $data['effective_date'] ?? null,
            'term_end_date' => $data['term_end_date'] ?? null,
            'committed_value_cents' => Money::parse($data['committed_value'] ?? 0),
            'value_cap_cents' => ! empty($data['value_cap']) ? Money::parse($data['value_cap']) : null,
            'cap_behavior' => ($data['cap_behavior'] ?? 'warn') === 'block' ? 'block' : 'warn',
            'signing_order' => ($data['signing_order'] ?? $contract->signing_order) === 'sequential' ? 'sequential' : 'parallel',
            'require_email_code' => array_key_exists('require_email_code', $data) ? (bool) $data['require_email_code'] : ($contract->require_email_code ?? true),
        ])->save();
        $client = Client::findOrFail($contract->client_id);
        $template = ! empty($data['template_id']) ? ContractTemplate::where('business_id', $business->id)->findOrFail($data['template_id']) : null;
        $source = RichText::normalize($data['body'] ?? null) ?: RichText::normalize($template?->body);
        $merge = $this->mergeData($business, $client, $contract, $data);
        [$body] = $this->render($source, $merge);

        $attrs = [
            'title' => $contract->title,
            'template_id' => $template?->id,
            'template_version' => $template?->version,
            'body' => $source,
            'snapshot' => ['input' => array_intersect_key($data, array_flip(['scope', 'fees', 'payment_terms_days', 'recurring_fee', 'milestones', 'committed_value', 'value_cap'])), 'merge' => $merge, 'rendered' => $body],
        ];
        if ($version) {
            if ($version->isLocked()) throw new DomainException('This version was sent and is immutable. Void it and create a new version.');
            $version->fill($attrs + ['status' => 'draft', 'rendered_document_id' => null, 'sha256' => null])->save();
            $version->recipients()->delete();
        } else {
            $n = (int) ContractVersion::where('contract_id', $contract->id)->max('version') + 1;
            $version = new ContractVersion($attrs + ['contract_id' => $contract->id, 'version' => $n, 'status' => 'draft', 'amends_version_id' => $amends?->id, 'created_by' => $this->ctx->user?->id]);
            $version->business_id = $business->id;
            $version->save();
            $contract->current_version_id = $version->id;
            if (! in_array($contract->status, ['completed'], true) || $amends) $contract->status = $amends ? $contract->status : 'draft';
            $contract->save();
        }
        $order = 1;
        foreach ($data['signers'] ?? [] as $s) {
            if (empty($s['email']) || empty($s['name'])) continue;
            $this->addRecipient($version, 'client_signer', $s['name'], $s['email'], $s['title'] ?? null, $contract->signing_order === 'sequential' ? $order++ : 1);
        }
        if (! empty($data['countersigner_user_id'])) {
            $user = User::findOrFail($data['countersigner_user_id']);
            if (! app(\App\Services\Access\PermissionService::class)->userCan($user, $business, 'contracts.countersign')) {
                throw new DomainException('The selected countersigner lacks countersign permission.');
            }
            $r = $this->addRecipient($version, 'countersigner', $user->label(), (string) $user->email, $data['countersigner_title'] ?? null, $contract->signing_order === 'sequential' ? $order++ : 1);
            $r->countersigner_user_id = $user->id;
            $r->save();
        }
        foreach ($data['cc'] ?? [] as $cc) {
            if (! empty($cc)) $this->addRecipient($version, 'cc', $cc, $cc, null, 99);
        }
        // Milestones are part of the agreement (billing plan), not of the signature artifact.
        ContractMilestone::where('contract_id', $contract->id)->where('status', 'pending')->whereNull('invoice_id')->delete();
        foreach ($data['milestones'] ?? [] as $m) {
            if (empty($m['name'])) continue;
            ContractMilestone::create(['business_id' => $business->id, 'contract_id' => $contract->id, 'name' => $m['name'], 'amount_cents' => Money::parse($m['amount'] ?? 0), 'due_date' => ($m['due_date'] ?? null) ?: null]);
        }

        return $version;
    }

    private function addRecipient(ContractVersion $v, string $role, string $name, string $email, ?string $title, int $order): ContractRecipient
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Invalid recipient email: '.$email);

        return ContractRecipient::create([
            'business_id' => $v->business_id,
            'contract_version_id' => $v->id,
            'role' => $role,
            'name' => $name,
            'email' => $email,
            'title' => $title,
            'sign_order' => $order,
        ]);
    }

    /** Validate merge fields and recipients, render and hash the immutable PDF. */
    public function prepare(ContractVersion $v): ContractVersion
    {
        return DB::transaction(function () use ($v) {
            $v = ContractVersion::where('business_id', $v->business_id)->lockForUpdate()->findOrFail($v->id);
            if ($v->isLocked()) throw new DomainException('Version is already sent.');
            $contract = Contract::findOrFail($v->contract_id);
            $signers = $v->recipients()->whereIn('role', ['client_signer', 'countersigner'])->count();
            if ($signers === 0 && ! $v->source_document_id) throw new DomainException('Add at least one signer.');
            if ($v->recipients()->where('role', 'client_signer')->count() === 0) throw new DomainException('Add at least one client signer.');
            if (! $v->source_document_id) {
                $missing = $this->render($v->body ?? '', $v->snapshot['merge'] ?? [])[1];
                if ($missing) throw new DomainException('Fill in the missing merge fields: '.implode(', ', $missing));
            }
            $bytes = $v->source_document_id
                ? \App\Models\PrivateDocument::findOrFail($v->source_document_id)->contents()
                : $this->pdf->render('pdf.contract', $this->documentModel($v, $contract));
            $doc = $this->docs->put(Business::find($v->business_id), 'contract_pdf', $contract->number.'-v'.$v->version.'.pdf', $bytes, 'application/pdf');
            $v->rendered_document_id = $doc->id;
            $v->sha256 = $doc->sha256;
            $v->status = 'prepared';
            $v->save();
            $contract->update(['status' => 'prepared']);
            $this->event($v, null, 'prepared', ['sha256' => $doc->sha256]);
            Audit::log($v->business_id, 'contract.prepared', $v, null, ['sha256' => $doc->sha256]);

            return $v;
        });
    }

    public function documentModel(ContractVersion $v, Contract $contract): array
    {
        $business = Business::find($v->business_id);

        return [
            'version' => $v,
            'contract' => $contract,
            'business' => $business,
            'html' => self::toHtml($v->snapshot['rendered'] ?? ''),
            'recipients' => $v->recipients()->whereIn('role', ['client_signer', 'countersigner'])->get(),
            'logo' => $this->docs->dataUri($business->logo),
            'accent' => $business->brand('accent'),
        ];
    }

    public function send(ContractVersion $v): void
    {
        DB::transaction(function () use ($v) {
            $v = ContractVersion::where('business_id', $v->business_id)->lockForUpdate()->findOrFail($v->id);
            if ($v->status !== 'prepared') throw new DomainException('Prepare (preview & lock) the agreement before sending.');
            $business = Business::findOrFail($v->business_id);
            $v->status = 'sent';
            $v->sent_at = now();
            $v->expires_at = now()->addDays($business->contract_link_ttl_days);
            $v->save();
            Contract::whereKey($v->contract_id)->update(['status' => 'sent']);
            $this->event($v, null, 'sent');
            $this->inviteEligible($v);
            Audit::log($business, 'contract.sent', $v);
        });
    }

    /** Invite signers whose turn it is (all in parallel mode; the lowest pending order in sequential mode). */
    public function inviteEligible(ContractVersion $v): void
    {
        $business = Business::findOrFail($v->business_id);
        $pending = $v->recipients()->where('role', '!=', 'cc')->whereIn('status', ['pending'])->get();
        if ($pending->isEmpty()) return;
        $contract = Contract::find($v->contract_id);
        if ($contract->signing_order === 'sequential') {
            $unsignedOrders = $v->recipients()->where('role', '!=', 'cc')->whereNotIn('status', ['signed'])->pluck('sign_order');
            $min = $unsignedOrders->min();
            $pending = $pending->where('sign_order', $min);
        }
        foreach ($pending as $r) {
            if ($r->role === 'countersigner') {
                $r->update(['status' => 'sent']);
                $this->event($v, $r, 'countersign_requested');
                continue;
            }
            [$token, $raw] = $this->links->issue($business, 'contract_sign', [
                'contract_version_id' => $v->id,
                'contract_recipient_id' => $r->id,
                'allowed_actions' => ['view', 'sign', 'decline', 'download'],
                'recipient_email' => $r->email,
            ], max(1, (int) now()->diffInDays($v->expires_at, false)) ?: $business->contract_link_ttl_days);
            $this->email->queueContractInvitation($r, url('/c/'.$raw));
            $r->update(['status' => 'sent']);
            $this->event($v, $r, 'invited', ['email' => $r->email]);
        }
    }

    public function remind(ContractRecipient $r): void
    {
        $v = ContractVersion::findOrFail($r->contract_version_id);
        if (! in_array($v->status, ['sent', 'partially_signed'], true) || $r->status !== 'sent' || $r->role !== 'client_signer') {
            throw new DomainException('This signer cannot be reminded.');
        }
        $business = Business::findOrFail($v->business_id);
        [$t, $raw] = $this->links->issue($business, 'contract_sign', [
            'contract_version_id' => $v->id, 'contract_recipient_id' => $r->id,
            'allowed_actions' => ['view', 'sign', 'decline', 'download'], 'recipient_email' => $r->email,
        ], max(1, (int) now()->diffInDays($v->expires_at, false)));
        $this->email->queueContractInvitation($r, url('/c/'.$raw), true);
        $this->event($v, $r, 'reminded');
    }

    public function void(ContractVersion $v, string $reason): void
    {
        DB::transaction(function () use ($v, $reason) {
            $v = ContractVersion::where('business_id', $v->business_id)->lockForUpdate()->findOrFail($v->id);
            if (in_array($v->status, ['completed', 'voided'], true)) throw new DomainException('Completed or voided versions cannot be voided.');
            $wasSent = in_array($v->status, ['sent', 'partially_signed'], true);
            $v->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => $reason]);
            PublicToken::where('contract_version_id', $v->id)->where('purpose', 'contract_sign')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            Contract::whereKey($v->contract_id)->where('current_version_id', $v->id)->update(['status' => 'voided']);
            $this->event($v, null, 'voided', ['reason' => $reason]);
            if ($wasSent) {
                foreach ($v->recipients()->where('role', 'client_signer')->get() as $r) {
                    $this->email->queueContractNotice($r, $v, 'contract_voided');
                }
            }
            Audit::log($v->business_id, 'contract.voided', $v, null, null, $reason);
        });
    }

    /** New editable version copied from the latest (after void), or an amendment of a completed version. */
    public function newVersion(Contract $contract, bool $amendment): ContractVersion
    {
        $latest = $contract->versions()->first();
        if (! $latest) throw new DomainException('No version to copy.');
        if ($amendment && $latest->status !== 'completed') throw new DomainException('Only completed agreements can be amended.');
        if (! $amendment && ! in_array($latest->status, ['voided', 'declined', 'expired'], true)) throw new DomainException('Void the current version first.');
        $business = Business::findOrFail($contract->business_id);
        $input = $latest->snapshot['input'] ?? [];
        $data = $input + [
            'title' => $amendment ? 'Amendment to '.$contract->title : $contract->title,
            'body' => $latest->body,
            'effective_date' => $contract->effective_date?->toDateString(),
            'term_end_date' => $contract->term_end_date?->toDateString(),
            'committed_value' => Money::toDecimalString($contract->committed_value_cents),
            'signers' => $latest->recipients()->where('role', 'client_signer')->get()->map(fn ($r) => ['name' => $r->name, 'email' => $r->email, 'title' => $r->title])->all(),
            'countersigner_user_id' => $latest->recipients()->where('role', 'countersigner')->value('countersigner_user_id'),
        ];
        if ($amendment) {
            // The original contract remains the executed record; amendments never rewrite it.
            $data['title'] = 'Amendment '.((int) ContractVersion::where('contract_id', $contract->id)->whereNotNull('amends_version_id')->count() + 1).' to '.$contract->title;
            $title = $contract->title;
            $v = DB::transaction(function () use ($business, $contract, $data, $latest) {
                $status = $contract->status;
                $v = $this->writeVersion($business, $contract, null, $data, $latest);
                $contract->refresh();
                $contract->title = $latest->title;
                $contract->status = $status;
                $contract->save();

                return $v;
            });
            Audit::log($business, 'contract.amendment_started', $v);

            return $v;
        }

        return DB::transaction(fn () => $this->writeVersion($business, $contract, null, $data));
    }

    public function uploadExternallySigned(Contract $contract, UploadedFile $file, string $provenance): ContractVersion
    {
        return DB::transaction(function () use ($contract, $file, $provenance) {
            $business = Business::findOrFail($contract->business_id);
            $doc = $this->docs->storeUpload($business, $file, 'contract_external', ['application/pdf']);
            $n = (int) ContractVersion::where('contract_id', $contract->id)->max('version') + 1;
            $v = new ContractVersion([
                'contract_id' => $contract->id, 'version' => $n, 'title' => $contract->title, 'status' => 'completed',
                'source_document_id' => $doc->id, 'rendered_document_id' => $doc->id, 'sha256' => $doc->sha256,
                'signed_document_id' => $doc->id, 'signed_sha256' => $doc->sha256, 'completed_at' => now(),
                'snapshot' => ['external' => true, 'provenance' => $provenance], 'created_by' => $this->ctx->user?->id,
            ]);
            $v->business_id = $business->id;
            $v->save();
            $contract->update(['current_version_id' => $v->id, 'status' => 'completed', 'externally_signed' => true, 'external_provenance' => $provenance]);
            $this->event($v, null, 'external_upload', ['provenance' => $provenance, 'sha256' => $doc->sha256]);
            Audit::log($business, 'contract.external_uploaded', $v, null, ['sha256' => $doc->sha256], $provenance);

            return $v;
        });
    }

    public function terminate(Contract $contract, string $date, string $reason): void
    {
        DB::transaction(function () use ($contract, $date, $reason) {
            $contract->update(['terminated_at' => $date, 'termination_reason' => $reason]);
            RecurringSchedule::where('business_id', $contract->business_id)->where('contract_id', $contract->id)->where('status', 'active')
                ->update(['status' => 'paused']);
            Audit::log($contract->business_id, 'contract.terminated', $contract, null, ['date' => $date], $reason);
        });
    }

    public function event(ContractVersion $v, ?ContractRecipient $r, string $event, array $details = []): SignatureEvent
    {
        $seq = (int) SignatureEvent::where('contract_version_id', $v->id)->lockForUpdate()->max('seq') + 1;
        $req = app()->runningInConsole() ? null : request();

        return SignatureEvent::create([
            'business_id' => $v->business_id,
            'contract_version_id' => $v->id,
            'contract_recipient_id' => $r?->id,
            'seq' => $seq,
            'event' => $event,
            'details' => $details ?: null,
            'document_sha256' => $v->sha256,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
        ]);
    }
}
