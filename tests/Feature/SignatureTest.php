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
            if (preg_match($re, $m->body, $x)) return $x[1];
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
        $code = $this->lastBodyMatching('/>\s*(\d{6})\s*</');
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

    public function test_contract_client_can_change_while_draft_and_signers_come_from_client_contacts(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $a = $this->makeClient($biz, 'Alpha Co');
        $b = $this->makeClient($biz, 'Beta Co');
        \App\Models\Contact::create(['business_id' => $biz->id, 'client_id' => $b->id, 'name' => 'Bea Signer', 'email' => 'bea@beta.test', 'title' => 'CEO', 'is_signer' => true]);
        \App\Models\Contact::create(['business_id' => $biz->id, 'client_id' => $b->id, 'name' => 'Not Signer', 'email' => 'ap@beta.test', 'is_signer' => false]);
        $svc = app(ContractService::class);
        $c = $svc->create($biz, ['client_id' => $a->id, 'title' => 'SOW', 'template_id' => $biz->contractTemplates()->value('id'), 'effective_date' => '2026-07-01',
            'scope' => 'x', 'fees' => 'y', 'signing_order' => 'parallel', 'signers' => [['name' => 'Al', 'email' => 'al@alpha.test']]]);

        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}/edit")->assertOk()->assertSee('Alpha Co')
            ->assertSee('data-signer-pick', false)->assertSee('bea@beta.test')->assertDontSee('ap@beta.test');
        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c->id}", ['client_id' => $b->id, 'title' => 'SOW', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'x', 'fees' => 'y', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Bea Signer', 'email' => 'bea@beta.test', 'title' => 'CEO']]])->assertRedirect();
        $c->refresh();
        $this->assertSame($b->id, $c->client_id);
        $this->assertStringContainsString('Beta Co', $c->currentVersion->snapshot['rendered']);
        $this->assertSame('bea@beta.test', $c->currentVersion->recipients()->value('email'));

        // Once sent, the client is locked.
        $v = $svc->prepare($c->currentVersion);
        $svc->send($v);
        $this->assertNotNull($svc->clientChangeBlocker($c->fresh()));
    }

    public function test_rich_text_body_is_sanitized_merged_safely_and_signable_with_drawn_signature(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz, 'Acme <b>Evil</b> & Co');
        $body = '<h2>Services</h2><p onclick="x()">Between <strong>{{business.legal_name}}</strong> and {{client.name}}.</p>'
            .'<script>alert(1)</script><ol><li>First</li><li>Second</li></ol><p><a href="javascript:alert(1)">bad</a> <a href="https://example.com">good</a></p>'
            .'<img src="https://tracker.example/x.png"><p style="color:red">{{agreement.scope}}</p>';
        $svc = app(ContractService::class);
        $c = $svc->create($biz, ['client_id' => $client->id, 'title' => 'Rich', 'body' => $body, 'scope' => "Line one\nLine two",
            'signing_order' => 'parallel', 'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']]]);
        $v = $c->currentVersion;
        $this->assertStringNotContainsString('<script', $v->body);
        $this->assertStringNotContainsString('onclick', $v->body);
        $this->assertStringNotContainsString('javascript:', $v->body);
        $this->assertStringNotContainsString('<img', $v->body);
        $this->assertStringNotContainsString('style=', $v->body);
        $rendered = $v->snapshot['rendered'];
        $this->assertStringContainsString('<strong>', $rendered);
        $this->assertStringContainsString('Acme &lt;b&gt;Evil&lt;/b&gt; &amp; Co', $rendered);
        $this->assertStringContainsString('Line one<br>', $rendered);

        // Templates are stored sanitized; unknown merge fields are rejected.
        $this->signIn($owner)->post("/b/{$biz->ulid}/contract-templates", ['name' => 'Rich T', 'body' => '<p>Hi {{client.name}}</p><script>x</script>'])->assertSessionHas('status');
        $this->assertSame('<p>Hi {{client.name}}</p>', \App\Models\ContractTemplate::where('name', 'Rich T')->value('body'));
        $this->signIn($owner)->post("/b/{$biz->ulid}/contract-templates", ['name' => 'Bad', 'body' => '<p>{{client.ssn}}</p>'])->assertSessionHas('error');
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}/edit")->assertOk()->assertSee('data-rte', false);
        $this->signIn($owner)->get("/b/{$biz->ulid}/contract-templates")->assertOk()->assertSee('data-rte', false);

        $v = $svc->prepare($v);
        $svc->send($v);
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);
        $token = $this->lastBodyMatching('#/c/([A-Za-z0-9_-]{40,64})#');
        $r = ContractRecipient::where('contract_version_id', $v->id)->first();
        $this->flushSession();
        $this->get('/c/'.$token)->assertRedirect('/c/r/'.$r->id);
        $this->get('/c/r/'.$r->id)->assertOk()->assertSee('<ol><li>First</li>', false)->assertDontSee('<script>alert', false);
        $this->post('/c/r/'.$r->id.'/code');
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);
        $this->post('/c/r/'.$r->id.'/verify', ['code' => $this->lastBodyMatching('/>\s*(\d{6})\s*</')]);
        $this->get('/c/r/'.$r->id)->assertOk()->assertSee('data-sig-canvas', false);

        // A drawn signature as produced by the mobile signature pad (PNG data URL).
        $img = imagecreatetruecolor(600, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 20, 200, 580, 120, imagecolorallocate($img, 17, 24, 39));
        ob_start(); imagepng($img); $png = ob_get_clean();
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat Signer', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'draw',
            'signature_data' => 'data:image/png;base64,'.base64_encode($png), 'document_sha256' => $v->sha256])->assertRedirect();
        $this->assertSame('draw', $r->fresh()->signature_kind);
        $this->assertSame('completed', $v->fresh()->status);
        // The signer lands on their completed copy instead of a dead link.
        $this->get('/c/r/'.$r->id)->assertOk()->assertSee('fully signed');
    }
}
