<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\PublicToken;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\LogNotificationGateway;
use App\Services\Notifications\OutboxProcessor;
use App\Services\Tax\W9Service;
use Tests\TestCase;

class W9Test extends TestCase
{
    private function setupW9(): array
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $biz->update(['legal_name' => 'Acme Consulting LLC', 'entity_type' => 'llc', 'address_line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']);
        $client = $this->makeClient($biz, 'Client Co');
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/w9", [
            'name' => 'Acme Consulting LLC', 'classification' => 'llc', 'llc_code' => 'S', 'address' => '1 Main St', 'city_state_zip' => 'Austin, TX 78701',
            'tin_type' => 'ein', 'tin' => '12-3456789', 'signer_name' => 'Rob McNutt', 'link_ttl_days' => 14, 'max_downloads' => 2, 'allow_client_requests' => 1,
        ])->assertSessionHas('status');

        return [$owner, $biz->fresh(), $client];
    }

    private function lastW9Link(): string
    {
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        preg_match('#/w9/([A-Za-z0-9_-]{43})#', end(LogNotificationGateway::$sent)->body, $m);

        return $m[1];
    }

    public function test_settings_validate_and_generate_a_locked_w9_pdf(): void
    {
        [$owner, $biz, $client] = $this->setupW9();
        $w9 = app(W9Service::class);
        $this->assertSame([], $w9->missing($biz));
        $this->assertSame('123456789', $w9->tin($biz));
        $this->assertStringNotContainsString('123456789', (string) $biz->w9_tin_encrypted);

        $this->signIn($owner)->get("/b/{$biz->ulid}/settings/w9/preview")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $this->signIn($owner)->get("/b/{$biz->ulid}/clients/{$client->id}/w9")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/Encrypt', $pdf); // copies for clients open and print, but can't be edited

        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/w9", ['name' => 'X', 'classification' => 'llc', 'address' => 'a', 'city_state_zip' => 'b',
            'tin_type' => 'ein', 'tin' => '123', 'signer_name' => 'R', 'link_ttl_days' => 14, 'max_downloads' => 2])->assertSessionHasErrors(['llc_code']);
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings")->assertOk()->assertSee('Ready to send')->assertSee('•••••6789');
    }

    public function test_classifications_beyond_llc_and_preview_while_incomplete(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $biz->update(['entity_type' => 'llc']);
        $w9 = app(W9Service::class);
        // An LLC must say how it's taxed, so nothing is assumed.
        $this->assertContains('Federal tax classification (line 3a)', $w9->missing($biz->fresh()));
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings/w9/preview")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings")->assertOk()->assertSee('data-show-for="llc"', false)->assertSee('W-9 preview');

        $base = ['name' => 'Pat Owner', 'address' => '1 Main St', 'city_state_zip' => 'Austin, TX 78701', 'tin_type' => 'ssn', 'tin' => '123-45-6789',
            'signer_name' => 'Pat Owner', 'link_ttl_days' => 14, 'max_downloads' => 3];
        // Single-member LLC: needs the LLC name on line 2; codes that don't apply are cleared.
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/w9", $base + ['classification' => 'disregarded'])->assertSessionHasErrors('business_name');
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/w9", $base + ['classification' => 'disregarded', 'business_name' => 'Pat Studio LLC',
            'llc_code' => 'P', 'exempt_payee_code' => '5', 'foreign_partners' => 1])->assertSessionHas('status');
        $s = $w9->settings($biz->fresh());
        $this->assertSame(['', '', false], [$s['llc_code'], $s['exempt_payee_code'], $s['foreign_partners']]);
        $this->assertSame('individual', W9Service::formBox($s['classification']));
        $this->assertSame([], $w9->missing($biz->fresh()));

        // A C corporation that isn't an LLC at all.
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/w9", array_merge($base, ['name' => 'Acme Inc', 'tin_type' => 'ein', 'tin' => '12-3456789', 'classification' => 'c_corp', 'exempt_payee_code' => '5']))->assertSessionHas('status');
        $this->assertSame(['c_corp', '5'], [$w9->settings($biz->fresh())['classification'], $w9->settings($biz->fresh())['exempt_payee_code']]);
        $this->assertStringStartsWith('%PDF', $w9->render($biz->fresh()));
    }

    public function test_staff_send_uses_expiring_limited_links_behind_a_landing_page(): void
    {
        [$owner, $biz, $client] = $this->setupW9();
        $this->signIn($owner)->post("/b/{$biz->ulid}/clients/{$client->id}/w9", ['recipients' => 'ap@client.test', 'message' => 'Here you go'])->assertSessionHas('status');
        $this->assertSame('w9', EmailOutbox::latest('id')->value('kind'));
        $this->assertTrue(AuditEvent::where('action', 'w9.sent')->where('object_type', 'Client')->exists());
        $raw = $this->lastW9Link();
        $this->assertStringContainsString('Form W-9 from', end(LogNotificationGateway::$sent)->subject);
        $this->flushSession();

        // A GET (what link scanners do) never returns the PDF.
        $this->get('/w9/'.$raw)->assertOk()->assertSee('Download W-9 (PDF)')->assertSee('2 downloads left');
        $this->post('/w9/'.$raw)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/w9/'.$raw)->assertOk();
        $this->post('/w9/'.$raw)->assertNotFound()->assertSee('no longer available');
        $this->get('/w9/'.$raw)->assertNotFound();
        $this->assertSame(2, DocumentAccessEvent::where('event', 'w9_downloaded')->count());

        // Expired links stop working too.
        $this->signIn($owner)->post("/b/{$biz->ulid}/clients/{$client->id}/w9", ['recipients' => 'ap@client.test']);
        $raw2 = $this->lastW9Link();
        $this->travel(15)->days();
        $this->flushSession();
        $this->post('/w9/'.$raw2)->assertNotFound();
        $this->assertNotNull(PublicToken::where('purpose', 'w9')->first()->max_uses);
    }

    public function test_client_can_request_w9_from_invoice_page(): void
    {
        [$owner, $biz, $client] = $this->setupW9();
        $this->actingSystem($owner);
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'Work', 'quantity' => '1', 'unit_price' => '100']]]));
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        preg_match('#/i/([A-Za-z0-9_-]{43})#', end(LogNotificationGateway::$sent)->body, $m);
        $this->flushSession();
        $this->get('/i/'.$m[1]);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Request W-9');

        $this->post('/i/v/'.$inv->ulid.'/w9')->assertRedirect('/i/v/'.$inv->ulid.'#w9');
        $this->get('/i/v/'.$inv->ulid)->assertSee('Download W-9 (PDF)');
        $this->post('/i/v/'.$inv->ulid.'/w9/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('client@example.test', EmailOutbox::where('kind', 'w9')->value('to_email'));
        $this->assertTrue(AuditEvent::where('action', 'w9.sent')->where('actor_type', 'client')->exists());

        // Turning off client requests hides it.
        $biz->update(['w9_settings' => array_merge($biz->w9_settings, ['allow_client_requests' => false])]);
        $this->get('/i/v/'.$inv->ulid)->assertDontSee('Request W-9');
        $this->post('/i/v/'.$inv->ulid.'/w9')->assertNotFound();

        // Without a granted invoice session nothing is available.
        $this->flushSession();
        $this->post('/i/v/'.$inv->ulid.'/w9/download')->assertNotFound();
    }
}
