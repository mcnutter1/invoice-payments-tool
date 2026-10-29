<?php

namespace Tests\Feature;

use App\Models\ContractRecipient;
use App\Models\SignatureEvent;
use App\Services\Contracts\ContractService;
use App\Services\Notifications\LogNotificationGateway;
use App\Services\Notifications\OutboxProcessor;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    private function sentContract(array $extra = [])
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz, 'Signer Co');
        $svc = app(ContractService::class);
        $c = $svc->create($biz, array_merge([
            'client_id' => $client->id, 'title' => 'Retainer', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'Advisory', 'fees' => '$1,000 / month', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']],
        ], $extra));
        $v = $svc->prepare($c->currentVersion);
        $svc->send($v);
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);

        return [$owner, $biz, $c->fresh(), $v->fresh()];
    }

    private function lastBodyMatching(string $re): ?string
    {
        foreach (array_reverse(LogNotificationGateway::$sent) as $m) {
            if (preg_match($re, $m->textBody, $x)) return $x[1];
        }

        return null;
    }

    public function test_full_signing_flow_with_otp_and_certificate(): void
    {
        [, $biz, $contract, $v] = $this->sentContract();
        $this->assertNotNull($v->sha256);
        $token = $this->lastBodyMatching('#/c/([A-Za-z0-9_-]{40,64})#');
        $r = ContractRecipient::where('contract_version_id', $v->id)->first();

        $this->get('/c/'.$token)->assertRedirect('/c/r/'.$r->id);
        $this->get('/c/r/'.$r->id)->assertOk()->assertSee('Verify your email');
        // Signing without verification fails.
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat Signer', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'type', 'document_sha256' => $v->sha256])->assertSessionHas('error');

        $this->post('/c/r/'.$r->id.'/code')->assertSessionHas('status');
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);
        $code = $this->lastBodyMatching('/\b(\d{6})\b/');
        $this->post('/c/r/'.$r->id.'/verify', ['code' => '000000' === $code ? '111111' : '000000'])->assertSessionHas('error');
        $this->post('/c/r/'.$r->id.'/verify', ['code' => $code])->assertSessionHas('status');

        // Stale document hash is rejected; correct hash signs.
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat Signer', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'type', 'document_sha256' => str_repeat('0', 64)])->assertSessionHas('error');
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat Signer', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'type', 'document_sha256' => $v->sha256])->assertRedirect();
        // Duplicate submission does not create a second signature (signing links close on completion).
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat Signer', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'type', 'document_sha256' => $v->sha256])->assertNotFound();
        $this->assertSame(1, SignatureEvent::where('contract_version_id', $v->id)->where('event', 'signed')->count());

        $v->refresh();
        $this->assertSame('completed', $v->status);
        $this->assertSame('completed', $contract->fresh()->status);
        $this->assertNotNull($v->signed_sha256);
        $this->assertNotSame($v->sha256, $v->signed_sha256);
        $this->assertNotNull($v->certificate_document_id);
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);
        $viewToken = $this->lastBodyMatching('#/c/([A-Za-z0-9_-]{40,64})#');
        $this->assertNotSame($token, $viewToken);
        $this->get('/c/'.$viewToken)->assertRedirect('/c/r/'.$r->id);
        $this->get('/c/r/'.$r->id)->assertOk()->assertSee('fully signed');
        $this->get('/c/r/'.$r->id.'/certificate')->assertOk();
        $this->get('/c/r/'.$r->id.'/signed')->assertOk();
    }

    public function test_voided_envelope_cannot_be_signed_and_sent_version_is_immutable(): void
    {
        [$owner, $biz, $contract, $v] = $this->sentContract();
        $token = $this->lastBodyMatching('#/c/([A-Za-z0-9_-]{40,64})#');
        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$contract->id}", ['title' => 'Changed', 'signing_order' => 'parallel'])->assertSessionHas('error');
        app(ContractService::class)->void($v, 'Terms changed');
        $this->flushSession();
        $this->get('/c/'.$token)->assertNotFound();
    }

    public function test_sequential_order_with_business_countersigner(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $svc = app(ContractService::class);
        $c = $svc->create($biz, ['client_id' => $client->id, 'title' => 'SOW', 'template_id' => $biz->contractTemplates()->value('id'), 'effective_date' => '2026-07-01',
            'scope' => 'x', 'fees' => 'y', 'signing_order' => 'sequential', 'signers' => [['name' => 'Pat', 'email' => 'pat@example.test']], 'countersigner_user_id' => $owner->id]);
        $v = $svc->prepare($c->currentVersion);
        $svc->send($v);
        $client = ContractRecipient::where('contract_version_id', $v->id)->where('role', 'client_signer')->first();
        $counter = ContractRecipient::where('contract_version_id', $v->id)->where('role', 'countersigner')->first();
        $this->assertSame('sent', $client->status);
        $this->assertSame('pending', $counter->status);

        $client->update(['status' => 'verified', 'verified_at' => now(), 'verified_email' => $client->email]);
        app(\App\Services\Contracts\SignatureService::class)->sign($v->fresh(), $client->fresh(), ['typed_name' => 'Pat', 'consent' => true, 'authority' => true, 'signature_kind' => 'type', 'document_sha256' => $v->sha256]);
        $this->assertSame('partially_signed', $v->fresh()->status);
        $this->assertSame('sent', $counter->fresh()->status);

        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c->id}/countersign", [
            'typed_name' => 'Owner Person', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'type', 'document_sha256' => $v->sha256,
        ])->assertRedirect();
        $this->assertSame('completed', $v->fresh()->status);
    }
}
