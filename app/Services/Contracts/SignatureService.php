<?php

namespace App\Services\Contracts;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Contract;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\ElectronicConsent;
use App\Models\PublicToken;
use App\Models\SignatureEvent;
use App\Services\Audit;
use App\Services\Documents\DocumentStore;
use App\Services\Documents\PdfRenderer;
use App\Services\Notifications\EmailComposer;
use App\Services\PublicAccess\PublicLinkService;
use Illuminate\Support\Facades\DB;

class SignatureService
{
    public const CONSENT_TEXT = 'I agree to use electronic records and signatures for this agreement. I understand that my electronic signature has the same effect as my handwritten signature, that I may request a paper copy or a non-electronic signing alternative from the sender at any time before signing, that I may withdraw this consent before signing by contacting the sender, and that I can download and keep a copy of the document and this consent. I confirm I can access and read PDF documents.';

    public function __construct(
        private readonly ContractService $contracts,
        private readonly DocumentStore $docs,
        private readonly PdfRenderer $pdf,
        private readonly EmailComposer $email,
        private readonly PublicLinkService $links,
    ) {}

    public function assertOpen(ContractVersion $v, ContractRecipient $r): void
    {
        if (! in_array($v->status, ['sent', 'partially_signed'], true)) throw new DomainException('This agreement is no longer open for signing.');
        if ($v->expires_at && $v->expires_at->isPast()) {
            $v->update(['status' => 'expired']);
            Contract::whereKey($v->contract_id)->update(['status' => 'expired']);
            throw new DomainException('This signing invitation has expired.');
        }
        if ($r->status === 'signed') throw new DomainException('You have already signed.');
        if ($r->status === 'declined') throw new DomainException('You declined this agreement.');
        if (! $r->isSigner()) throw new DomainException('This recipient does not sign.');
        if (! in_array($r->status, ['sent', 'viewed', 'verified'], true)) throw new DomainException('It is not your turn to sign yet.');
    }

    public function recordView(ContractVersion $v, ContractRecipient $r): void
    {
        if ($r->status === 'sent') $r->update(['status' => 'viewed']);
        $this->contracts->event($v, $r, 'viewed');
    }

    private function codeHash(ContractRecipient $r, string $code): string
    {
        return hash_hmac('sha256', $r->id.'|'.$r->email.'|'.$code, (string) config('app.key'));
    }

    public function sendCode(ContractVersion $v, ContractRecipient $r): void
    {
        DB::transaction(function () use ($v, $r) {
            $r = ContractRecipient::lockForUpdate()->findOrFail($r->id);
            $this->assertOpen($v, $r);
            $cfg = config('finance.signing');
            if ($r->otp_sends >= $cfg['otp_max_sends']) throw new DomainException('Too many codes requested. Contact the sender for help.');
            if ($r->otp_last_sent_at && $r->otp_last_sent_at->gt(now()->subSeconds($cfg['otp_resend_cooldown_seconds']))) {
                throw new DomainException('Please wait a minute before requesting another code.');
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $r->update([
                'otp_hash' => $this->codeHash($r, $code),
                'otp_expires_at' => now()->addMinutes($cfg['otp_ttl_minutes']),
                'otp_attempts' => 0,
                'otp_sends' => $r->otp_sends + 1,
                'otp_last_sent_at' => now(),
            ]);
            $this->email->queueSigningCode($r, $code);
            $this->contracts->event($v, $r, 'verification_code_sent', ['email' => $r->email]);
        });
    }

    public function verifyCode(ContractVersion $v, ContractRecipient $r, string $code): bool
    {
        return DB::transaction(function () use ($v, $r, $code) {
            $r = ContractRecipient::lockForUpdate()->findOrFail($r->id);
            $this->assertOpen($v, $r);
            if (! $r->otp_hash || ! $r->otp_expires_at || $r->otp_expires_at->isPast()) throw new DomainException('Code expired. Request a new code.');
            if ($r->otp_attempts >= config('finance.signing.otp_max_attempts')) throw new DomainException('Too many attempts. Request a new code.');
            $r->otp_attempts++;
            $ok = hash_equals($r->otp_hash, $this->codeHash($r, preg_replace('/\D/', '', $code)));
            if ($ok) {
                $r->otp_hash = null;
                $r->verified_at = now();
                $r->verified_email = $r->email;
                $r->status = 'verified';
            }
            $r->save();
            $this->contracts->event($v, $r, $ok ? 'email_verified' : 'verification_failed');

            return $ok;
        });
    }

    /**
     * @param array{typed_name:string, typed_title?:string|null, consent:bool, authority:bool, signature_kind:string, signature_data?:string|null, document_sha256:string} $input
     * @param string $method 'email_otp' | 'email_link' | 'authenticated_session'
     */
    public function sign(ContractVersion $v, ContractRecipient $r, array $input, string $method = 'email_otp'): ContractRecipient
    {
        return DB::transaction(function () use ($v, $r, $input, $method) {
            $v = ContractVersion::where('business_id', $v->business_id)->lockForUpdate()->findOrFail($v->id);
            $r = ContractRecipient::where('contract_version_id', $v->id)->lockForUpdate()->findOrFail($r->id);
            $this->assertOpen($v, $r);
            if ($method === 'email_otp' && (! $r->verified_at || $r->verified_at->lt(now()->subMinutes(30)))) {
                throw new DomainException('Verify your email with a one-time code before signing.');
            }
            if (! hash_equals((string) $v->sha256, (string) ($input['document_sha256'] ?? ''))) {
                throw new DomainException('The document changed since you opened it. Reload and review it again.');
            }
            if (empty($input['consent'])) throw new DomainException('You must consent to electronic records and signatures.');
            if (empty($input['authority'])) throw new DomainException('Please confirm you are authorized to sign.');
            $name = trim((string) $input['typed_name']);
            if (mb_strlen($name) < 2) throw new DomainException('Type your full legal name.');

            $kind = $input['signature_kind'] === 'draw' ? 'draw' : 'type';
            $imgBytes = '';
            $docId = null;
            if ($kind === 'draw') {
                $data = (string) ($input['signature_data'] ?? '');
                if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) throw new DomainException('Draw your signature or switch to typed signature.');
                $imgBytes = base64_decode($m[1], true) ?: '';
                if (strlen($imgBytes) < 100 || strlen($imgBytes) > 300_000 || ! str_starts_with($imgBytes, "\x89PNG")) throw new DomainException('Signature image is invalid.');
                $docId = $this->docs->put(Business::find($v->business_id), 'signature', 'signature.png', $imgBytes, 'image/png')->id;
            }
            $req = request();
            ElectronicConsent::create([
                'business_id' => $v->business_id,
                'contract_recipient_id' => $r->id,
                'consent_version' => config('finance.signing.consent_version'),
                'consent_text' => self::CONSENT_TEXT,
                'accepted_at' => now(),
                'ip' => $req->ip(),
                'user_agent' => mb_substr((string) $req->userAgent(), 0, 255),
            ]);
            $sigHash = hash('sha256', $name.'|'.$kind.'|'.$imgBytes.'|'.$v->sha256);
            $r->update([
                'status' => 'signed',
                'signed_at' => now(),
                'typed_name' => $name,
                'typed_title' => $input['typed_title'] ?? null,
                'signature_kind' => $kind,
                'signature_document_id' => $docId,
                'signature_hash' => $sigHash,
                'verification_method' => $method,
                'ip' => $req->ip(),
                'user_agent' => mb_substr((string) $req->userAgent(), 0, 255),
            ]);
            $this->contracts->event($v, $r, 'consented', ['consent_version' => config('finance.signing.consent_version')]);
            $this->contracts->event($v, $r, 'signed', [
                'typed_name' => $name, 'title' => $input['typed_title'] ?? null, 'kind' => $kind,
                'signature_hash' => $sigHash, 'verification' => $method, 'verified_email' => $r->verified_email,
            ]);

            $remaining = $v->recipients()->whereIn('role', ['client_signer', 'countersigner'])->where('status', '!=', 'signed')->count();
            if ($remaining === 0) {
                $this->complete($v);
            } else {
                $v->update(['status' => 'partially_signed']);
                Contract::whereKey($v->contract_id)->update(['status' => 'partially_signed']);
                $this->contracts->inviteEligible($v);
            }
            Audit::log($v->business_id, 'contract.signed', $r, null, ['recipient' => $r->email, 'method' => $method], null, $method === 'authenticated_session' ? null : 'public');

            return $r;
        });
    }

    public function decline(ContractVersion $v, ContractRecipient $r, string $reason): void
    {
        DB::transaction(function () use ($v, $r, $reason) {
            $v = ContractVersion::lockForUpdate()->findOrFail($v->id);
            $r = ContractRecipient::lockForUpdate()->findOrFail($r->id);
            $this->assertOpen($v, $r);
            $r->update(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => mb_substr($reason, 0, 1000)]);
            $v->update(['status' => 'declined']);
            Contract::whereKey($v->contract_id)->update(['status' => 'declined']);
            PublicToken::where('contract_version_id', $v->id)->where('purpose', 'contract_sign')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->contracts->event($v, $r, 'declined', ['reason' => $reason]);
            foreach ($v->recipients()->where('id', '!=', $r->id)->where('role', '!=', 'countersigner')->get() as $other) {
                $this->email->queueContractNotice($other, $v, 'contract_declined');
            }
            Audit::log($v->business_id, 'contract.declined', $r, null, null, $reason, 'public');
        });
    }

    /** Freeze final artifact + audit certificate once all required parties signed. */
    public function complete(ContractVersion $v): void
    {
        $contract = Contract::findOrFail($v->contract_id);
        $business = Business::findOrFail($v->business_id);
        $model = $this->contracts->documentModel($v, $contract);
        $model['signed'] = true;
        $model['signatures'] = $v->recipients()->whereIn('role', ['client_signer', 'countersigner'])->get()->map(function ($r) {
            $img = $r->signature_document_id ? $this->docs->dataUri(\App\Models\PrivateDocument::find($r->signature_document_id)) : null;

            return ['recipient' => $r, 'image' => $img];
        });
        $model['original_sha256'] = $v->sha256;
        $signedBytes = $v->source_document_id
            ? $this->pdf->render('pdf.contract-signature-page', $model)
            : $this->pdf->render('pdf.contract', $model);
        $signed = $this->docs->put($business, 'contract_signed', $contract->number.'-v'.$v->version.'-signed.pdf', $signedBytes, 'application/pdf');

        $this->contracts->event($v, null, 'completed', ['signed_sha256' => $signed->sha256]);
        $events = SignatureEvent::where('contract_version_id', $v->id)->orderBy('seq')->get();
        $cert = $this->pdf->render('pdf.certificate', [
            'version' => $v, 'contract' => $contract, 'business' => $business, 'events' => $events,
            'recipients' => $v->recipients()->get(), 'original_sha256' => $v->sha256, 'signed_sha256' => $signed->sha256,
            'consent_text' => self::CONSENT_TEXT, 'consent_version' => config('finance.signing.consent_version'),
        ]);
        $certDoc = $this->docs->put($business, 'contract_certificate', $contract->number.'-v'.$v->version.'-certificate.pdf', $cert, 'application/pdf');

        $v->update(['status' => 'completed', 'completed_at' => now(), 'signed_document_id' => $signed->id, 'signed_sha256' => $signed->sha256, 'certificate_document_id' => $certDoc->id]);
        DB::afterCommit(fn () => app(ContractBillingService::class)->runSafely($contract->id));
        $isAmendment = (bool) $v->amends_version_id;
        $contract->update(array_filter([
            'status' => 'completed',
            'effective_date' => $contract->effective_date ?? $business->localToday()->toDateString(),
            'current_version_id' => $v->id,
        ]));
        PublicToken::where('contract_version_id', $v->id)->where('purpose', 'contract_sign')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        foreach ($v->recipients()->get() as $r) {
            [$t, $raw] = $this->links->issue($business, 'contract_view', [
                'contract_version_id' => $v->id, 'contract_recipient_id' => $r->id,
                'allowed_actions' => ['view', 'download'], 'recipient_email' => $r->email,
            ], 90);
            $this->email->queueContractNotice($r, $v, 'contract_completed', url('/c/'.$raw));
        }
        Audit::log($business, $isAmendment ? 'contract.amendment_completed' : 'contract.completed', $v, null, ['signed_sha256' => $signed->sha256], null, 'system');
    }
}
