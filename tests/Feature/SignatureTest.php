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

    public function test_email_code_can_be_waived_and_countersigner_reuses_saved_signature(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $svc = app(ContractService::class);
        $png = function () { $img = imagecreatetruecolor(600, 200); imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255)); imageline($img, 10, 150, 590, 60, 0); ob_start(); imagepng($img); return 'data:image/png;base64,'.base64_encode(ob_get_clean()); };
        $make = function () use ($svc, $biz, $client, $owner) {
            $c = $svc->create($biz, ['client_id' => $client->id, 'title' => 'NDA', 'template_id' => $biz->contractTemplates()->value('id'), 'effective_date' => '2026-07-01',
                'scope' => 'x', 'fees' => 'y', 'signing_order' => 'parallel', 'require_email_code' => false,
                'signers' => [['name' => 'Pat', 'email' => 'pat@example.test']], 'countersigner_user_id' => $owner->id]);
            $v = $svc->prepare($c->currentVersion);
            $svc->send($v);

            return [$c, $v->fresh()];
        };

        [$c1, $v1] = $make();
        $this->assertFalse($c1->fresh()->require_email_code);
        app(OutboxProcessor::class)->run(20, microtime(true) + 10);
        $token = $this->lastBodyMatching('#/c/([A-Za-z0-9_-]{40,64})#');
        $r = ContractRecipient::where('contract_version_id', $v1->id)->where('role', 'client_signer')->first();
        $this->get('/c/'.$token);
        $this->get('/c/r/'.$r->id)->assertOk()->assertDontSee('Verify your email')->assertSee('data-sig-canvas', false)
            ->assertSee('value="draw" checked', false);
        $this->post('/c/r/'.$r->id.'/sign', ['typed_name' => 'Pat', 'consent' => 1, 'authority' => 1, 'signature_kind' => 'draw', 'signature_data' => $png(), 'document_sha256' => $v1->sha256])->assertRedirect();
        $this->assertSame('email_link', $r->fresh()->verification_method);
        $this->assertStringContainsString('no one-time code', $r->fresh()->verificationLabel());

        // Countersign with a drawn signature and save it for reuse.
        $this->flushSession();
        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c1->id}/countersign", ['typed_name' => 'Owner', 'consent' => 1, 'authority' => 1,
            'signature_kind' => 'draw', 'signature_data' => $png(), 'save_signature' => 1, 'document_sha256' => $v1->sha256])->assertRedirect();
        $this->assertSame('completed', $v1->fresh()->status);
        $this->assertNotNull(\App\Models\Membership::where('business_id', $biz->id)->where('user_id', $owner->id)->value('signature_document_id'));

        [$c2, $v2] = $make();
        $counter = ContractRecipient::where('contract_version_id', $v2->id)->where('role', 'countersigner')->first();
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c2->id}/countersign")->assertOk()->assertSee('Your saved signature')->assertSee('value="saved" checked', false);
        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c2->id}/countersign", ['typed_name' => 'Owner', 'consent' => 1, 'authority' => 1,
            'signature_kind' => 'saved', 'document_sha256' => $v2->sha256])->assertRedirect();
        $this->assertSame('draw', $counter->fresh()->signature_kind);
        $this->assertNotNull($counter->fresh()->signature_document_id);
        $this->signIn($owner)->post("/b/{$biz->ulid}/my-signature/delete")->assertRedirect();
        $this->assertNull(\App\Models\Membership::where('business_id', $biz->id)->where('user_id', $owner->id)->value('signature_document_id'));
    }

    public function test_signed_agreement_creates_milestone_invoices_and_monthly_schedule(): void
    {
        $past = now()->subDays(2)->toDateString();
        $future = now()->addMonths(2)->toDateString();
        [$owner, $biz, $c, $v] = $this->sentContract([
            'term_start_date' => now()->addMonth()->startOfMonth()->toDateString(), 'term_end_date' => now()->addYear()->toDateString(),
            'monthly_fee' => '500', 'monthly_fee_description' => 'Advisory retainer', 'billing_mode' => 'issue_send', 'billing_auto' => true,
            'milestones' => [
                ['name' => 'Kickoff', 'description' => 'Discovery workshop', 'amount' => '1000', 'due_date' => $past],
                ['name' => 'Final delivery', 'amount' => '2500.50', 'due_date' => $future],
            ],
        ]);
        $merge = $v->snapshot['merge'];
        $this->assertSame('$500.00', $merge['agreement.monthly_fee']);
        $this->assertSame('Kickoff', $merge['agreement.milestone_1_name']);
        $this->assertSame('$2,500.50', $merge['agreement.milestone_2_amount']);
        $this->assertSame(\Illuminate\Support\Carbon::parse($future)->format('F j, Y'), $merge['agreement.milestone_2_date']);
        $this->assertSame('$3,500.50', $merge['agreement.milestone_total']);
        [$rendered, $missing] = app(ContractService::class)->render('{{agreement.milestone_1_name}} {{ agreement.milestone_2_amount }}', $merge);
        $this->assertSame('Kickoff $2,500.50', $rendered);
        $this->assertSame([], $missing);

        $r = ContractRecipient::where('contract_version_id', $v->id)->first();
        $r->update(['status' => 'verified', 'verified_at' => now(), 'verified_email' => $r->email]);
        app(\App\Services\Contracts\SignatureService::class)->sign($v->fresh(), $r->fresh(), ['typed_name' => 'Pat', 'consent' => true, 'authority' => true, 'signature_kind' => 'type', 'document_sha256' => $v->sha256]);
        $this->assertSame('completed', $v->fresh()->status);

        $ms = \App\Models\ContractMilestone::where('contract_id', $c->id)->orderBy('due_date')->get();
        $this->assertCount(2, $ms);
        $this->assertTrue($ms->every(fn ($m) => $m->invoice_id));
        $kick = \App\Models\Invoice::with('lines')->find($ms[0]->invoice_id);
        $final = \App\Models\Invoice::with('lines')->find($ms[1]->invoice_id);
        $this->assertSame($v->id, $kick->contract_version_id);
        $this->assertSame('Kickoff', $kick->lines[0]->name);
        $this->assertStringContainsString('Discovery workshop', $kick->lines[0]->description);
        $this->assertStringContainsString('Milestone payment per agreement '.$c->number, $kick->lines[0]->description);
        $this->assertFalse($kick->isDraft(), 'due milestone is issued');
        $this->assertSame(1000_00, $kick->total_cents);
        $this->assertTrue($final->isDraft(), 'future milestone waits as a draft');
        $this->assertSame($future, $final->issue_date->toDateString());
        $this->assertSame('scheduled', $ms[1]->status);
        $this->assertTrue(\App\Models\EmailOutbox::where('business_id', $biz->id)->where('kind', 'invoice')->exists());

        $s = \App\Models\RecurringSchedule::where('contract_id', $c->id)->with('lines')->sole();
        $this->assertSame('contract', $s->origin);
        $this->assertSame('month', $s->cadence);
        $this->assertSame(now()->addMonth()->startOfMonth()->toDateString(), $s->start_date->toDateString());
        $this->assertSame('Advisory retainer', $s->lines[0]->name);
        $this->assertTrue((bool) $s->auto_send);

        // Idempotent.
        app(\App\Services\Contracts\ContractBillingService::class)->onExecuted($c->fresh());
        $this->assertSame(2, \App\Models\Invoice::where('contract_id', $c->id)->count());
        $this->assertSame(1, \App\Models\RecurringSchedule::where('contract_id', $c->id)->count());

        // Deleting the scheduled draft frees the milestone again.
        app(\App\Services\Invoicing\InvoiceService::class)->deleteDraft($final);
        $this->assertSame('pending', $ms[1]->fresh()->status);
        $this->assertNull($ms[1]->fresh()->invoice_id);
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}")->assertOk()->assertSee('Billing plan')->assertSee('Create invoices now')->assertSee('Preview contract');
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}/versions/{$v->version}/preview")->assertOk()
            ->assertSee('this is how signers see')->assertSee('Advisory')->assertSee('Pat Signer');
    }

    public function test_term_begin_and_open_ended_term_are_separate_from_effective_date(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz, 'Term Co');
        $base = ['client_id' => $client->id, 'title' => 'MSA', 'signing_order' => 'parallel', 'effective_date' => '2026-11-01',
            'body' => '<p>Effective {{agreement.effective_date}}. Term {{agreement.term}}. Ends {{agreement.term_end_date}}.</p>'];

        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts", $base + ['term_start_date' => '2027-01-01', 'term_end_date' => '2026-12-01'])
            ->assertSessionHasErrors('term_end_date');

        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts", $base + ['term_start_date' => '2027-01-01', 'term_end_date' => '2027-12-31', 'term_open_ended' => 1])->assertRedirect();
        $c = \App\Models\Contract::latest('id')->first();
        $this->assertSame('2027-01-01', $c->term_start_date->toDateString());
        $this->assertTrue($c->term_open_ended);
        $this->assertNull($c->term_end_date); // open-ended wins over a stale end date
        $this->assertSame('Jan 1, 2027 – open-ended', $c->termLabel());
        $this->assertStringContainsString('Effective November 1, 2026. Term from January 1, 2027 and continues until terminated by either party.', $c->currentVersion->snapshot['rendered']);
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}")->assertSee('Term Jan 1, 2027 – open-ended');
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}/edit")->assertSee('Open-ended');

        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c->id}", array_diff_key($base, ['client_id' => 1]) + ['term_start_date' => '2027-01-01', 'term_end_date' => '2027-12-31', 'term_open_ended' => 0])->assertRedirect();
        $c->refresh();
        $this->assertSame('2027-12-31', $c->term_end_date->toDateString());
        $this->assertStringContainsString('Term from January 1, 2027 through December 31, 2027. Ends December 31, 2027.', $c->currentVersion->snapshot['rendered']);

        // Billing waits for the term to begin even after the agreement is in effect.
        $c->update(['billing_override_reason' => 'test']);
        $this->assertFalse($c->fresh()->isActiveForBilling(\Illuminate\Support\Carbon::parse('2026-12-15')));
        $this->assertTrue($c->fresh()->isActiveForBilling(\Illuminate\Support\Carbon::parse('2027-02-01')));
        $this->assertFalse($c->fresh()->isActiveForBilling(\Illuminate\Support\Carbon::parse('2028-01-02')));
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
            ->assertSee('data-signer-pick', false)->assertSee('bea@beta.test')->assertSee('ap@beta.test')->assertSee('&quot;signer&quot;:false', false);
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
