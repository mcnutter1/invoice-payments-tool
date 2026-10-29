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
        preg_match('#/i/([A-Za-z0-9_-]{40,64})#', $m->textBody, $x);

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
        $this->assertStringContainsString('Download PDF:', $mail->textBody);
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

    public function test_voided_invoice_cancels_queued_sends(): void
    {
        [$owner, $biz, , $inv] = $this->issued();
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/send", ['recipients' => 'client@example.test']);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/void", ['reason' => 'mistake']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $this->assertSame('cancelled', EmailOutbox::first()->status);
        $this->assertCount(0, LogNotificationGateway::$sent);
    }
}
