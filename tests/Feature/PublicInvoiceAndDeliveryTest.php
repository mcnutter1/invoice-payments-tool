<?php

namespace Tests\Feature;

use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Models\JournalLine;
use App\Models\PaymentClaim;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\LogNotificationGateway;
use App\Services\Notifications\McNuttNotificationGateway;
use App\Services\Notifications\NotificationGateway;
use App\Services\Notifications\OutboxProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicInvoiceAndDeliveryTest extends TestCase
{
    private function issued()
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'Consulting', 'quantity' => '2', 'unit_price' => '500']]]));

        return [$owner, $biz, $client, $inv];
    }

    private function linkFromLastEmail(): string
    {
        $m = end(LogNotificationGateway::$sent);
        preg_match('#/i/([A-Za-z0-9_-]{40,64})#', $m->body, $x);

        return $x[1];
    }

    public function test_send_view_report_without_financial_change(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test'])->assertRedirect();
        $this->assertSame('queued', $inv->fresh()->delivery_status);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $row = EmailOutbox::first();
        $this->assertSame('accepted', $row->status);
        $this->assertNull($row->encrypted_body); // raw link material purged after acceptance
        $this->assertSame('sent', $inv->fresh()->delivery_status);
        $mail = end(LogNotificationGateway::$sent);
        $this->assertStringContainsString('<!DOCTYPE html>', $mail->body);
        $this->assertStringContainsString('Download PDF', $mail->body);
        $this->assertStringContainsString('/t/o/', $mail->body);
        $token = $this->linkFromLastEmail();
        $this->flushSession();

        $ledgerBefore = JournalLine::count();
        // Scanner-style GET of the link: grants a session but never changes financial state.
        $this->withHeader('User-Agent', 'Mimecast Safelinks')->get('/i/'.$token)->assertRedirect('/i/v/'.$inv->ulid);
        $this->assertTrue(DocumentAccessEvent::where('probable_automation', true)->exists());
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee($inv->number)->assertSee('How to pay')->assertDontSee('Internal')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/i/v/'.$inv->ulid.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/i/v/'.$inv->ulid.'/report-payment', ['method' => 'zelle', 'amount' => '1000.00', 'reported_date' => now()->toDateString()])->assertRedirect();
        $this->get('/i/v/'.$inv->ulid)->assertSee('Payment reported');
        $this->assertSame(1, PaymentClaim::count());
        $this->assertSame($ledgerBefore, JournalLine::count());
        $this->assertSame(100000, $inv->fresh()->balance_cents);
    }

    public function test_public_access_requires_grant_and_rejects_revoked_or_unrelated(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $this->get('/i/v/'.$inv->ulid)->assertNotFound();
        $this->get('/i/'.str_repeat('a', 43))->assertNotFound();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $token = $this->linkFromLastEmail();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/revoke-links");
        $this->flushSession();
        $this->get('/i/'.$token)->assertNotFound();
        // Invoice access never grants contract signing pages.
        $this->get('/c/r/1')->assertNotFound();
    }

    public function test_outbox_classifies_provider_results(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        config(['finance.notifications.api_token' => 'tok', 'finance.notifications.driver' => 'mcnutt']);
        $this->app->instance(NotificationGateway::class, new McNuttNotificationGateway(config('finance.notifications')));
        $this->actingSystem($owner);
        $composer = app(\App\Services\Notifications\EmailComposer::class);

        $next = fn () => Http::response(['ok' => true, 'id' => 'msg-1'], 200);
        Http::fake(['notifications.mcnutt.cloud/*' => function () use (&$next) { return $next(); }]);
        $composer->queueInvoice($inv, ['a@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $this->assertSame('accepted', EmailOutbox::latest('id')->first()->status);
        $this->assertSame('msg-1', EmailOutbox::latest('id')->first()->provider_message_id);
        Http::assertSent(fn ($r) => $r->url() === 'https://notifications.mcnutt.cloud/api/send.php' && $r['channel'] === 'email' && $r->hasHeader('Authorization', 'Bearer tok') && ! isset($r['attachments']));

        $next = fn () => Http::response(['error' => 'down'], 503);
        $composer->queueInvoice($inv, ['b@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $row = EmailOutbox::latest('id')->first();
        $this->assertSame('queued', $row->status);
        $this->assertTrue($row->available_at->isFuture());

        $next = fn () => Http::response(['error' => 'invalid from'], 422);
        $composer->queueInvoice($inv, ['c@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $this->assertSame('failed', EmailOutbox::latest('id')->first()->status);

        $next = fn () => Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
        $composer->queueInvoice($inv, ['d@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $row = EmailOutbox::latest('id')->first();
        $this->assertSame('delivery_unknown', $row->status);
        $this->assertTrue($row->needs_review);
    }

    public function test_zelle_qr_shows_in_settings_and_on_invoices_for_the_same_zelle_account(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $payments = fn (array $extra = []) => $this->signIn($owner)->post("/b/{$biz->ulid}/settings/payments", array_merge([
            'payment_methods' => ['zelle'], 'zelle_recipient_name' => 'Biz LLC', 'zelle_email' => 'pay@biz.test', 'confirm_change' => 1,
        ], $extra));
        $payments()->assertSessionHas('status');
        $svc = app(InvoiceService::class);
        $this->actingSystem($owner);
        $inv = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'payment_methods' => ['zelle'], 'lines' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '50']]]));
        $this->assertNull($inv->instructions_snapshot['zelle']['qr_document_id']);

        $payments(['zelle_qr' => \Illuminate\Http\UploadedFile::fake()->image('qr.png', 200, 200)])->assertSessionHas('status');
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings")->assertOk()->assertSee('alt="Current Zelle QR code"', false);

        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $token = $this->linkFromLastEmail();
        $this->flushSession();
        $this->get('/i/'.$token);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Scan with your bank')->assertSee('src="data:image/png;base64,', false);

        // A QR for a different Zelle recipient is never attached to an invoice issued with other details.
        $payments(['zelle_email' => 'other@biz.test'])->assertSessionHas('status');
        $this->flushSession();
        $this->get('/i/'.$token);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertDontSee('Scan with your bank');
    }

    public function test_voided_invoice_cancels_queued_sends(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/void", ['reason' => 'mistake']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $this->assertSame('cancelled', EmailOutbox::first()->status);
        $this->assertCount(0, LogNotificationGateway::$sent);
    }

    public function test_email_open_click_pay_and_copy_are_tracked_as_activity(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $body = end(LogNotificationGateway::$sent)->body;
        preg_match('#/t/o/([A-Za-z0-9_-]{43})\.gif#', $body, $px);
        preg_match('#/i/([A-Za-z0-9_-]{43})\?e=([A-Za-z0-9_-]{43})&amp;a=view#', $body, $pay);
        $this->assertNotEmpty($px);
        $this->assertNotEmpty($pay);
        $this->flushSession();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh)')->get('/t/o/'.$px[1].'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get('/t/o/'.str_repeat('x', 43).'.gif')->assertOk(); // unknown token: same response, nothing recorded
        $this->get('/i/'.$pay[1].'?e='.$pay[2].'&a=view')->assertRedirect('/i/v/'.$inv->ulid);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Pay now');
        $this->get('/i/v/'.$inv->ulid.'/pay')->assertRedirect('/i/v/'.$inv->ulid.'#howtopay');
        $this->postJson('/i/v/'.$inv->ulid.'/event', ['event' => 'payment_details_copied', 'detail' => 'memo'])->assertNoContent();
        $this->postJson('/i/v/'.$inv->ulid.'/event', ['event' => 'invoice_paid'])->assertStatus(422);

        $row = EmailOutbox::first();
        $this->assertSame(1, $row->open_count);
        $this->assertSame(1, $row->click_count);
        $events = DocumentAccessEvent::where('invoice_id', $inv->id)->pluck('event')->all();
        foreach (['email_opened', 'email_clicked', 'pay_clicked', 'invoice_viewed', 'payment_details_copied'] as $e) {
            $this->assertContains($e, $events);
        }
        $this->assertSame(1, DocumentAccessEvent::where('event', 'email_opened')->count());
        $this->assertSame(100000, $inv->fresh()->balance_cents);

        $this->flushSession();
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()
            ->assertSee('Opened the email')->assertSee('Clicked “View invoice” in the email')->assertSee('Copied payment details (memo)');
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices")->assertOk()->assertSee('Clicked pay');
    }

    public function test_invoice_email_avoids_common_phishing_signals(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $biz->update(['zelle_email' => 'pay@biz.test', 'address_line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX']);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $m = end(LogNotificationGateway::$sent);
        $this->assertStringContainsString(' via McNutt Finance <', $m->from);
        $this->assertDoesNotMatchRegularExpression('/&#(8199|65279|847);|display:none|opacity:0/', $m->body);
        foreach (['secure link', 'Pay invoice', 'pay@biz.test', 'expires'] as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $m->body);
        }
        $this->assertStringContainsString('1 Main St', $m->body);
        $this->assertStringContainsString('You received this email because', $m->body);
    }

    public function test_cancelled_invoices_are_hidden_by_default_and_purge_removes_everything(): void
    {
        [$owner, $biz, $client, $inv] = $this->issued();
        $svc = app(InvoiceService::class);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/void", ['reason' => 'Wrong client']);
        $this->assertSame('Cancelled', $inv->fresh()->statusLabel());
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices")->assertDontSee($inv->number);
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices?show_cancelled=1")->assertSee($inv->number)->assertSee('line-through');

        // Wrong confirmation is refused; correct confirmation purges invoice, ledger, links and emails.
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/purge", ['reason' => 'Test data', 'confirm' => 'nope'])->assertSessionHas('error');
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/purge", ['reason' => 'Test data', 'confirm' => $inv->number])->assertRedirect();
        $this->assertNull(\App\Models\Invoice::find($inv->id));
        $this->assertSame(0, JournalLine::where('invoice_id', $inv->id)->count());
        $this->assertSame(0, \App\Models\JournalEntry::where('source_key', 'like', 'invoice:'.$inv->id.':%')->count());
        $this->assertSame(0, EmailOutbox::where('invoice_id', $inv->id)->count());
        $this->assertSame(0, \App\Models\PublicToken::where('invoice_id', $inv->id)->count());
        $this->assertTrue(\App\Models\AuditEvent::where('action', 'invoice.purged')->exists());

        // Invoices that ever had money applied can only be cancelled.
        $paid = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '100']]]));
        $this->actingSystem($owner);
        app(\App\Services\Payments\PaymentService::class)->record($biz, ['client_id' => $client->id, 'method' => 'zelle', 'amount_cents' => 10000, 'received_date' => now()->toDateString()], [$paid->id => 10000]);
        $this->assertNotNull($svc->purgeBlocker($paid->fresh()));
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$paid->id}/purge", ['reason' => 'x', 'confirm' => $paid->number])->assertSessionHas('error');
        $this->assertNotNull($paid->fresh());
    }
}
