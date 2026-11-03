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
use Illuminate\Support\Carbon;
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
        'signer.name', 'signer.email', 'signer.title', 'signer_2.name', 'signer_2.email', 'signer_2.title',
        'countersigner.name', 'countersigner.email', 'countersigner.title',
        'agreement.title', 'agreement.number', 'agreement.effective_date', 'agreement.term_start_date', 'agreement.term_end_date', 'agreement.term',
        'agreement.scope', 'agreement.fees', 'agreement.payment_terms_days', 'agreement.committed_value',
        'agreement.milestones', 'agreement.recurring_fee',
        'agreement.monthly_fee', 'agreement.monthly_fee_description', 'agreement.billing_start_date', 'agreement.milestone_total',
        'agreement.milestone_1_name', 'agreement.milestone_1_amount', 'agreement.milestone_1_date', 'agreement.milestone_1_description',
        'agreement.milestone_2_name', 'agreement.milestone_2_amount', 'agreement.milestone_2_date', 'agreement.milestone_2_description',
        'agreement.milestone_3_name', 'agreement.milestone_3_amount', 'agreement.milestone_3_date', 'agreement.milestone_3_description',
        'agreement.milestone_4_name', 'agreement.milestone_4_amount', 'agreement.milestone_4_date', 'agreement.milestone_4_description',
        'agreement.milestone_5_name', 'agreement.milestone_5_amount', 'agreement.milestone_5_date', 'agreement.milestone_5_description',
    ];

    public function mergeData(Business $b, Client $c, Contract $contract, array $input): array
    {
        $date = fn ($d) => $d ? Carbon::parse($d)->format('F j, Y') : '';
        $rows = collect($input['milestones'] ?? [])->filter(fn ($m) => ! empty($m['name']))->values();
        $milestones = $rows->map(fn ($m) => '• '.$m['name'].' — '.Money::format(Money::parse($m['amount'] ?? 0)).(! empty($m['due_date']) ? ', due '.$date($m['due_date']) : '')
            .(! empty($m['description']) ? ': '.trim($m['description']) : ''))->implode("\n");
        $indexed = [];
        for ($i = 1; $i <= 5; $i++) {
            $m = $rows[$i - 1] ?? null;
            $indexed['agreement.milestone_'.$i.'_name'] = $m['name'] ?? '';
            $indexed['agreement.milestone_'.$i.'_amount'] = $m ? Money::format(Money::parse($m['amount'] ?? 0)) : '';
            $indexed['agreement.milestone_'.$i.'_date'] = $m ? $date($m['due_date'] ?? null) : '';
            $indexed['agreement.milestone_'.$i.'_description'] = trim((string) ($m['description'] ?? ''));
        }
        $monthly = $contract->billing_monthly_cents > 0 ? Money::format($contract->billing_monthly_cents) : '';
        $billingStart = $contract->billing_start_date ?? $contract->term_start_date ?? $contract->effective_date;
        $signers = collect($input['signers'] ?? [])->filter(fn ($s) => ! empty($s['name']) && ! empty($s['email']))->values();
        foreach (['signer' => 0, 'signer_2' => 1] as $key => $i) {
            $indexed[$key.'.name'] = trim((string) ($signers[$i]['name'] ?? ''));
            $indexed[$key.'.email'] = strtolower(trim((string) ($signers[$i]['email'] ?? '')));
            $indexed[$key.'.title'] = trim((string) ($signers[$i]['title'] ?? ''));
        }
        $counter = ! empty($input['countersigner_user_id']) ? User::find($input['countersigner_user_id']) : null;
        $indexed['countersigner.name'] = $counter?->label() ?? '';
        $indexed['countersigner.email'] = (string) ($counter?->email ?? '');
        $indexed['countersigner.title'] = $counter ? trim((string) ($input['countersigner_title'] ?? '')) : '';

        return $indexed + [
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
            'agreement.term_start_date' => ($contract->term_start_date ?? $contract->effective_date)?->format('F j, Y') ?? '',
            'agreement.term_end_date' => $contract->term_end_date?->format('F j, Y') ?? 'terminated by either party',
            'agreement.term' => ($s = ($contract->term_start_date ?? $contract->effective_date)?->format('F j, Y'))
                ? ($contract->term_end_date ? 'from '.$s.' through '.$contract->term_end_date->format('F j, Y') : 'from '.$s.' and continues until terminated by either party')
                : '',
            'agreement.scope' => trim((string) ($input['scope'] ?? '')),
            'agreement.fees' => trim((string) ($input['fees'] ?? '')),
            'agreement.payment_terms_days' => (string) ($input['payment_terms_days'] ?? $b->default_terms_days),
            'agreement.committed_value' => Money::format($contract->committed_value_cents),
            'agreement.milestones' => $milestones,
            'agreement.recurring_fee' => trim((string) ($input['recurring_fee'] ?? '')) ?: ($monthly ? $monthly.' per month' : ''),
            'agreement.monthly_fee' => $monthly,
            'agreement.monthly_fee_description' => $monthly ? ($contract->billing_monthly_description ?: 'Monthly fee') : '',
            'agreement.billing_start_date' => $monthly ? $date($billingStart) : '',
            'agreement.milestone_total' => $rows->isNotEmpty() ? Money::format($rows->sum(fn ($m) => Money::parse($m['amount'] ?? 0))) : '',
        ];
    }

    /** @return array{0:string,1:list<string>} [body, unresolved fields] */
    public function render(string $template, array $data): array
    {
        $missing = [];
        $html = RichText::isHtml($template);
        $body = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/', function ($m) use ($data, &$missing, $html) {
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
            'term_start_date' => $data['term_start_date'] ?? null,
            'term_open_ended' => $openEnded = ! empty($data['term_open_ended']),
            'term_end_date' => $openEnded ? null : ($data['term_end_date'] ?? null),
            'committed_value_cents' => Money::parse($data['committed_value'] ?? 0),
            'value_cap_cents' => ! empty($data['value_cap']) ? Money::parse($data['value_cap']) : null,
            'cap_behavior' => ($data['cap_behavior'] ?? 'warn') === 'block' ? 'block' : 'warn',
            'signing_order' => ($data['signing_order'] ?? $contract->signing_order) === 'sequential' ? 'sequential' : 'parallel',
            'require_email_code' => array_key_exists('require_email_code', $data) ? (bool) $data['require_email_code'] : ($contract->require_email_code ?? true),
            'billing_monthly_cents' => ! empty($data['monthly_fee']) ? Money::parse($data['monthly_fee']) : null,
            'billing_monthly_description' => ! empty($data['monthly_fee_description']) ? $data['monthly_fee_description'] : null,
            'billing_start_date' => ($data['billing_start_date'] ?? null) ?: null,
            'billing_auto' => array_key_exists('billing_auto', $data) ? (bool) $data['billing_auto'] : ($contract->billing_auto ?? true),
            'billing_mode' => array_key_exists($data['billing_mode'] ?? '', ContractBillingService::MODES) ? $data['billing_mode'] : ($contract->billing_mode ?? 'draft'),
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
            'snapshot' => ['input' => array_intersect_key($data, array_flip(['scope', 'fees', 'payment_terms_days', 'recurring_fee', 'milestones', 'committed_value', 'value_cap', 'monthly_fee', 'monthly_fee_description', 'billing_start_date'])), 'merge' => $merge, 'rendered' => $body],
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
        $billed = ContractMilestone::where('contract_id', $contract->id)->get()
            ->map(fn ($m) => $m->name.'|'.$m->amount_cents.'|'.$m->due_date?->toDateString())->all();
        foreach ($data['milestones'] ?? [] as $m) {
            if (empty($m['name'])) continue;
            $cents = Money::parse($m['amount'] ?? 0);
            // Already-invoiced milestones carried into a new version/amendment must not be billed twice.
            if (in_array($m['name'].'|'.$cents.'|'.(($m['due_date'] ?? null) ?: ''), $billed, true)) continue;
            ContractMilestone::create(['business_id' => $business->id, 'contract_id' => $contract->id, 'name' => $m['name'], 'description' => ($m['description'] ?? null) ?: null, 'amount_cents' => $cents, 'due_date' => ($m['due_date'] ?? null) ?: null]);
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
            'font' => in_array($business->brand('font'), \App\Services\Documents\PdfRenderer::FONTS, true) ? $business->brand('font') : 'DejaVu Sans',
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
            $presigned = $v->recipients()->where('role', '!=', 'cc')->where('status', 'signed')->exists();
            if ($presigned) $v->update(['status' => 'partially_signed']);
            Contract::whereKey($v->contract_id)->update(['status' => $presigned ? 'partially_signed' : 'sent']);
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

    public function remind(ContractRecipient $r, ?string $reason = null): void
    {
        $v = ContractVersion::findOrFail($r->contract_version_id);
        if (! in_array($v->status, ['sent', 'partially_signed'], true) || ! in_array($r->status, ['sent', 'viewed', 'verified'], true) || $r->role !== 'client_signer') {
            throw new DomainException('This signer cannot be reminded.');
        }
        $business = Business::findOrFail($v->business_id);
        [$t, $raw] = $this->links->issue($business, 'contract_sign', [
            'contract_version_id' => $v->id, 'contract_recipient_id' => $r->id,
            'allowed_actions' => ['view', 'sign', 'decline', 'download'], 'recipient_email' => $r->email,
        ], max(1, (int) now()->diffInDays($v->expires_at, false)));
        $this->email->queueContractInvitation($r, url('/c/'.$raw), true);
        $this->event($v, $r, 'reminded');
        Audit::log($business, 'contract.reminder_queued', $v, null, ['recipient' => $r->email], $reason);
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
            'term_start_date' => $contract->term_start_date?->toDateString(),
            'term_end_date' => $contract->term_end_date?->toDateString(),
            'term_open_ended' => $contract->term_open_ended,
            'committed_value' => Money::toDecimalString($contract->committed_value_cents),
            'monthly_fee' => $contract->billing_monthly_cents ? Money::toDecimalString($contract->billing_monthly_cents) : null,
            'monthly_fee_description' => $contract->billing_monthly_description,
            'billing_start_date' => $contract->billing_start_date?->toDateString(),
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
            DB::afterCommit(fn () => app(ContractBillingService::class)->runSafely($contract->id));

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

    /** Signed agreements whose term has ended become 'expired'; their contract-driven billing is paused. Run by the scheduler. */
    public function expireEndedTerms(): int
    {
        $n = 0;
        $due = Contract::where('status', 'completed')->whereNull('terminated_at')->whereNull('ended_at')
            ->whereNotNull('term_end_date')->whereDate('term_end_date', '<', now()->addDay()->toDateString())->get();
        foreach ($due as $c) {
            $today = Business::find($c->business_id)?->localToday() ?? today();
            if (! $c->term_end_date->lt($today)) continue;
            DB::transaction(function () use ($c) {
                $c->update(['status' => 'expired', 'ended_at' => now()]);
                RecurringSchedule::where('business_id', $c->business_id)->where('contract_id', $c->id)->where('status', 'active')->update(['status' => 'paused']);
                Audit::log($c->business_id, 'contract.term_ended', $c, null, ['term_end_date' => $c->term_end_date->toDateString()], null, 'system');
            });
            $n++;
        }

        return $n;
    }

    /** Bring an ended (expired or terminated) signed agreement back into force with a new end date. */
    public function reactivate(Contract $contract, ?string $newEndDate, string $reason): void
    {
        if (! $contract->isEnded()) throw new DomainException('Only an expired or terminated signed agreement can be reactivated.');
        $business = Business::findOrFail($contract->business_id);
        if ($newEndDate && Carbon::parse($newEndDate)->lt($business->localToday())) throw new DomainException('The new end date must be today or later.');
        DB::transaction(function () use ($contract, $newEndDate, $reason) {
            $before = ['status' => $contract->status, 'term_end_date' => $contract->term_end_date?->toDateString(), 'terminated_at' => $contract->terminated_at?->toDateString()];
            $contract->update([
                'status' => 'completed', 'ended_at' => null, 'terminated_at' => null, 'termination_reason' => null,
                'term_end_date' => $newEndDate ?: null, 'term_open_ended' => ! $newEndDate,
            ]);
            foreach (RecurringSchedule::where('business_id', $contract->business_id)->where('contract_id', $contract->id)->where('status', 'paused')->get() as $s) {
                $s->update(['status' => 'active', 'end_date' => $s->origin === 'contract' ? ($newEndDate ?: null) : $s->end_date]);
            }
            Audit::log($contract->business_id, 'contract.reactivated', $contract, $before, ['term_end_date' => $newEndDate], $reason);
        });
    }

    /** Start a renewal: a new draft agreement for the next term, copied from the signed one. */
    public function renew(Contract $contract): Contract
    {
        if (! $contract->wasExecuted()) throw new DomainException('Only signed agreements can be renewed.');
        $source = $contract->versions()->where('status', 'completed')->whereNull('source_document_id')->first()
            ?? $contract->versions()->whereNull('source_document_id')->first();
        $business = Business::findOrFail($contract->business_id);
        $start = $contract->term_end_date ? $contract->term_end_date->copy()->addDay() : $business->localToday();
        $oldStart = $contract->term_start_date ?? $contract->effective_date;
        $end = $contract->term_end_date && $oldStart ? $start->copy()->addDays($oldStart->diffInDays($contract->term_end_date)) : null;
        $input = $source?->snapshot['input'] ?? [];
        $data = [
            'client_id' => $contract->client_id,
            'title' => $contract->title,
            'body' => $source?->body ?? '',
            'scope' => $input['scope'] ?? null, 'fees' => $input['fees'] ?? null, 'payment_terms_days' => $input['payment_terms_days'] ?? null,
            'recurring_fee' => $input['recurring_fee'] ?? null,
            'effective_date' => $start->toDateString(),
            'term_start_date' => $start->toDateString(),
            'term_end_date' => $end?->toDateString(),
            'term_open_ended' => ! $end && $contract->term_open_ended,
            'committed_value' => Money::toDecimalString($contract->committed_value_cents),
            'value_cap' => $contract->value_cap_cents ? Money::toDecimalString($contract->value_cap_cents) : null,
            'cap_behavior' => $contract->cap_behavior,
            'signing_order' => $contract->signing_order,
            'require_email_code' => $contract->require_email_code,
            'monthly_fee' => $contract->billing_monthly_cents ? Money::toDecimalString($contract->billing_monthly_cents) : null,
            'monthly_fee_description' => $contract->billing_monthly_description,
            'billing_start_date' => null,
            'billing_auto' => $contract->billing_auto,
            'billing_mode' => $contract->billing_mode,
            'signers' => $source ? $source->recipients()->where('role', 'client_signer')->get()->map(fn ($r) => ['name' => $r->name, 'email' => $r->email, 'title' => $r->title])->all() : [],
            'countersigner_user_id' => $source?->recipients()->where('role', 'countersigner')->value('countersigner_user_id'),
        ];

        return DB::transaction(function () use ($business, $contract, $data) {
            $new = $this->create($business, $data);
            $new->update(['renewed_from_id' => $contract->id]);
            Audit::log($business, 'contract.renewal_started', $new, null, ['renewed_from' => $contract->number]);

            return $new->fresh();
        });
    }

    /** Why a contract can't be deleted, or null if it can. */
    public function deleteBlocker(Contract $c): ?string
    {
        if ($c->invoices()->exists()) return 'invoices are linked to it. Void or delete those invoices first, or terminate the agreement instead.';
        if ($c->schedules()->exists()) return 'recurring billing is linked to it. Delete that schedule first, or terminate the agreement instead.';
        if ($c->versions()->whereIn('status', ['sent', 'partially_signed'])->exists()) return 'it is out for signature. Void it first.';
        if (Contract::where('renewed_from_id', $c->id)->exists()) return 'a renewal was created from it.';

        return null;
    }

    /** Permanently remove an agreement, its versions, signature trail and stored documents. */
    public function delete(Contract $contract, string $reason): void
    {
        if ($blocker = $this->deleteBlocker($contract)) throw new DomainException('This agreement can’t be deleted: '.$blocker);
        DB::transaction(function () use ($contract, $reason) {
            $bid = $contract->business_id;
            $versionIds = ContractVersion::where('business_id', $bid)->where('contract_id', $contract->id)->pluck('id');
            $recipients = ContractRecipient::where('business_id', $bid)->whereIn('contract_version_id', $versionIds);
            $recipientIds = (clone $recipients)->pluck('id');
            $docIds = array_merge(
                (clone $recipients)->pluck('signature_document_id')->all(),
                ContractVersion::whereIn('id', $versionIds)->get(['source_document_id', 'rendered_document_id', 'signed_document_id', 'certificate_document_id'])
                    ->flatMap(fn ($v) => [$v->source_document_id, $v->rendered_document_id, $v->signed_document_id, $v->certificate_document_id])->all(),
            );
            DB::table('public_tokens')->where('business_id', $bid)->where(fn ($q) => $q->whereIn('contract_version_id', $versionIds)->orWhereIn('contract_recipient_id', $recipientIds))->delete();
            DB::table('email_outbox')->where('business_id', $bid)->where(fn ($q) => $q->whereIn('contract_version_id', $versionIds)->orWhereIn('contract_recipient_id', $recipientIds))
                ->update(['contract_version_id' => null, 'contract_recipient_id' => null]);
            DB::table('document_access_events')->where('business_id', $bid)->whereIn('contract_version_id', $versionIds)->update(['contract_version_id' => null]);
            DB::table('expenses')->where('business_id', $bid)->where('contract_id', $contract->id)->update(['contract_id' => null]);
            DB::table('electronic_consents')->where('business_id', $bid)->whereIn('contract_recipient_id', $recipientIds)->delete();
            DB::table('signature_events')->where('business_id', $bid)->whereIn('contract_version_id', $versionIds)->delete();
            ContractRecipient::whereIn('id', $recipientIds)->delete();
            ContractMilestone::where('business_id', $bid)->where('contract_id', $contract->id)->delete();
            $contract->update(['current_version_id' => null]);
            ContractVersion::whereIn('id', $versionIds)->delete();
            $snapshot = ['number' => $contract->number, 'title' => $contract->title, 'client_id' => $contract->client_id, 'status' => $contract->status];
            $contract->delete();
            $this->docs->deleteMany($bid, array_values(array_unique(array_filter($docIds))));
            Audit::log($bid, 'contract.deleted', $contract, $snapshot, null, $reason);
        });
    }

    public function event(ContractVersion $v, ?ContractRecipient $r, string $event, array $details = []): SignatureEvent
    {
        $seq = (int) SignatureEvent::where('contract_version_id', $v->id)->lockForUpdate()->max('seq') + 1;
        $req = app()->runningInConsole() ? null : request();
        if ($req && ! app(\App\Support\Context::class)->user) {
            Audit::log($v->business_id, 'client.contract_'.$event, $v, null, array_filter([
                'email' => $r?->email,
                'source' => \App\Services\PublicAccess\ClientActivity::source($req->userAgent(), \App\Services\PublicAccess\PublicLinkService::looksAutomated($req->userAgent())),
            ]), null, 'client');
        }

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
