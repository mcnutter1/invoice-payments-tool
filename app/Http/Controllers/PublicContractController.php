<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ContractRecipient;
use App\Models\ContractVersion;
use App\Models\PrivateDocument;
use App\Models\PublicToken;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\SignatureService;
use App\Services\Documents\DocumentStore;
use App\Services\PublicAccess\ClientActivity;
use App\Services\PublicAccess\PublicLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Recipient-specific signing pages. Invoice links never grant access here. */
class PublicContractController extends Controller
{
    public function __construct(private readonly PublicLinkService $links, private readonly SignatureService $sig) {}

    private function notFound()
    {
        return response()->view('public.not-available', [], 404);
    }

    public function exchange(Request $request, string $token)
    {
        $t = $this->links->find($token, 'contract_sign') ?? $this->links->find($token, 'contract_view');
        if (! $t || ! $t->contract_recipient_id) return $this->notFound();
        $this->links->grant($request, $t);
        app(ClientActivity::class)->record($request, [
            'business_id' => $t->business_id, 'public_token_id' => $t->id, 'contract_version_id' => $t->contract_version_id,
            'event' => 'link_accessed',
        ], $t->recipient_email);
        if ($request->query('e')) {
            app(\App\Services\Notifications\EmailTracker::class)->recordClick($request->query('e'), $request->query('a'), $t, $request);
        }
        $suffix = ['signed' => '/signed', 'certificate' => '/certificate'][$request->query('d')] ?? '';

        return redirect('/c/r/'.$t->contract_recipient_id.$suffix);
    }

    /** @return array{0:?ContractRecipient,1:?ContractVersion,2:?PublicToken} */
    private function resolve(Request $request, int $recipientId): array
    {
        $t = $this->links->granted($request, 'contract_sign', fn ($t) => $t->contract_recipient_id === $recipientId)
            ?? $this->links->granted($request, 'contract_view', fn ($t) => $t->contract_recipient_id === $recipientId);
        if (! $t) return [null, null, null];
        $r = ContractRecipient::where('business_id', $t->business_id)->find($recipientId);
        $v = $r ? ContractVersion::where('business_id', $t->business_id)->find($r->contract_version_id) : null;
        if (! $r || ! $v || $v->id !== $t->contract_version_id) return [null, null, null];

        return [$r, $v, $t];
    }

    public function show(Request $request, int $recipient)
    {
        [$r, $v, $t] = $this->resolve($request, $recipient);
        if (! $r) return $this->notFound();
        $business = Business::find($v->business_id);
        $signing = $t->purpose === 'contract_sign' && in_array($v->status, ['sent', 'partially_signed'], true) && $r->isSigner() && in_array($r->status, ['sent', 'viewed', 'verified'], true);
        if ($signing) {
            $this->sig->recordView($v, $r);
        } else {
            // Signing views are in the signature trail; other views (e.g. completed agreements) are tracked here.
            app(ClientActivity::class)->record($request, ['business_id' => $v->business_id, 'public_token_id' => $t->id, 'contract_version_id' => $v->id, 'event' => 'contract_viewed'], $r->email);
        }

        return view('public.contract', [
            'recipient' => $r->fresh(),
            'version' => $v,
            'contract' => \App\Models\Contract::with('client')->find($v->contract_id),
            'business' => $business,
            'html' => $v->source_document_id ? null : ContractService::toHtml($v->snapshot['rendered'] ?? ''),
            'signing' => $signing,
            'requireCode' => (bool) (\App\Models\Contract::find($v->contract_id)?->require_email_code ?? true),
            'consent' => SignatureService::CONSENT_TEXT,
            'logo' => app(DocumentStore::class)->dataUri($business->logo),
            'accent' => $business->brand('accent'),
            'evidence' => $v->status === 'completed' ? $this->sig->evidence($v) : null,
        ]);
    }

    private function serve(Request $request, int $recipient, string $column, string $name)
    {
        [$r, $v, $t] = $this->resolve($request, $recipient);
        if (! $r || ! $v->{$column}) return $this->notFound();
        app(ClientActivity::class)->record($request, ['business_id' => $v->business_id, 'public_token_id' => $t->id, 'contract_version_id' => $v->id, 'event' => 'contract_downloaded',
            'detail' => ['signed_document_id' => 'signed', 'certificate_document_id' => 'certificate'][$column] ?? 'document'], $r->email);

        return app(DocumentStore::class)->download(PrivateDocument::where('business_id', $v->business_id)->findOrFail($v->{$column}), $name, $request->boolean('inline'));
    }

    public function document(Request $request, int $recipient)
    {
        return $this->serve($request, $recipient, 'rendered_document_id', 'agreement.pdf');
    }

    public function signed(Request $request, int $recipient)
    {
        return $this->serve($request, $recipient, 'signed_document_id', 'agreement-signed.pdf');
    }

    public function certificate(Request $request, int $recipient)
    {
        return $this->serve($request, $recipient, 'certificate_document_id', 'signature-certificate.pdf');
    }

    public function consent(Request $request, int $recipient)
    {
        [$r] = $this->resolve($request, $recipient);
        if (! $r) return $this->notFound();

        return response("Electronic records and signature consent (version ".config('finance.signing.consent_version').")\n\n".SignatureService::CONSENT_TEXT."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="esign-consent.txt"',
        ]);
    }

    private function signerContext(Request $request, int $recipient): array
    {
        [$r, $v, $t] = $this->resolve($request, $recipient);
        if (! $r || $t->purpose !== 'contract_sign' || ! $t->allows('sign')) abort(404);

        return [$r, $v];
    }

    public function sendCode(Request $request, int $recipient)
    {
        [$r, $v] = $this->signerContext($request, $recipient);
        $this->sig->sendCode($v, $r);

        return back()->with('status', 'We emailed a 6-digit code to '.$this->mask($r->email).'.');
    }

    public function verify(Request $request, int $recipient)
    {
        [$r, $v] = $this->signerContext($request, $recipient);
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        if (! $this->sig->verifyCode($v, $r, $code)) {
            throw new DomainException('That code is not correct.');
        }

        return back()->with('status', 'Email verified. You can now sign.');
    }

    public function sign(Request $request, int $recipient)
    {
        [$r, $v] = $this->signerContext($request, $recipient);
        $data = $request->validate([
            'typed_name' => ['required', 'string', 'max:120'],
            'typed_title' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'],
            'authority' => ['accepted'],
            'signature_kind' => ['required', Rule::in(['type', 'draw'])],
            'signature_data' => ['nullable', 'string', 'max:400000'],
            'document_sha256' => ['required', 'string', 'size:64'],
        ]);
        $this->sig->sign($v, $r, $data + ['consent' => true, 'authority' => true], $this->signMethod($v, $r));
        // Completion revokes signing links; hand this browser the signer's new view link so they land on their copy.
        $view = PublicToken::where('business_id', $r->business_id)->where('purpose', 'contract_view')
            ->where('contract_recipient_id', $r->id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first();
        if ($view) $this->links->grant($request, $view);

        return redirect('/c/r/'.$r->id)->with('status', 'Thank you — your signature was recorded.');
    }

    public function decline(Request $request, int $recipient)
    {
        [$r, $v] = $this->signerContext($request, $recipient);
        $this->sig->decline($v, $r, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason']);

        return redirect('/c/r/'.$r->id)->with('status', 'You declined this agreement. The sender has been notified.');
    }

    /** Agreements can waive the one-time code; a recent verification is still recorded as the stronger method. */
    private function signMethod(ContractVersion $v, ContractRecipient $r): string
    {
        $verified = $r->verified_at && $r->verified_at->gt(now()->subMinutes(30));
        $required = (bool) (\App\Models\Contract::find($v->contract_id)?->require_email_code ?? true);

        return $verified || $required ? 'email_otp' : 'email_link';
    }

    private function mask(string $email): string
    {
        [$u, $d] = explode('@', $email) + [1 => ''];

        return mb_substr($u, 0, 2).str_repeat('•', max(1, mb_strlen($u) - 2)).'@'.$d;
    }
}
