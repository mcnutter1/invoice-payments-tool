<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContractRecipient;
use App\Models\DocumentAccessEvent;
use App\Models\EmailOutbox;
use App\Services\Contracts\ContractService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use App\Services\Notifications\LogNotificationGateway;
use App\Services\Notifications\OutboxProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    private function sentInvoice(array $reminders)
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $biz->update(['reminder_settings' => $reminders]);
        $client = $this->makeClient($biz);
        $this->actingSystem($owner);
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'Consulting', 'quantity' => '1', 'unit_price' => '500']]]));
        app(EmailComposer::class)->queueInvoice($inv, ['client@example.test']);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);

        return [$owner, $biz, $inv->fresh()];
    }

    private function reminders(): int
    {
        $this->artisan('finance:reminders')->assertSuccessful();

        return EmailOutbox::where('kind', 'reminder')->count();
    }

    public function test_unviewed_invoice_is_reminded_every_n_days_until_viewed(): void
    {
        [, , $inv] = $this->sentInvoice(['invoice_unviewed' => ['enabled' => true, 'every_days' => 2]]);
        $this->travel(1)->days();
        $this->assertSame(0, $this->reminders());
        $this->travel(1)->days();
        $this->assertSame(1, $this->reminders());
        $this->assertSame(1, $this->reminders()); // same day: no duplicate
        $this->assertSame('client@example.test', EmailOutbox::where('kind', 'reminder')->value('to_email'));
        $this->assertTrue(AuditEvent::where('action', 'invoice.reminder_queued')->where('reason', 'like', '%not yet viewed%')->exists());

        // A scanner hit doesn't count as a view; a real view stops the reminders.
        DocumentAccessEvent::create(['business_id' => $inv->business_id, 'invoice_id' => $inv->id, 'event' => 'invoice_viewed', 'probable_automation' => true]);
        $this->travel(2)->days();
        $this->assertSame(2, $this->reminders());
        DocumentAccessEvent::create(['business_id' => $inv->business_id, 'invoice_id' => $inv->id, 'event' => 'invoice_viewed', 'probable_automation' => false]);
        $this->travel(2)->days();
        $this->assertSame(2, $this->reminders());
    }

    public function test_past_due_invoice_is_reminded_with_report_payment_action(): void
    {
        [, , $inv] = $this->sentInvoice(['invoice_overdue' => ['enabled' => true, 'every_days' => 3]]);
        $this->travelTo($inv->due_date->copy()->addDays(2)->setTime(15, 0));
        $this->assertSame(0, $this->reminders());
        $this->travel(1)->days();
        $this->assertSame(1, $this->reminders());
        $this->travel(2)->days();
        $this->assertSame(1, $this->reminders());
        $this->travel(1)->days();
        $this->assertSame(2, $this->reminders());
        $this->assertTrue(AuditEvent::where('action', 'invoice.reminder_queued')->where('reason', 'like', '%past due%')->exists());

        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $body = end(LogNotificationGateway::$sent)->body;
        $this->assertStringContainsString('mailed a check', $body);
        preg_match('#/i/([A-Za-z0-9_-]{43})\?e=([A-Za-z0-9_-]{43})&amp;a=report#', $body, $m);
        $this->assertNotEmpty($m);
        $this->flushSession();
        $this->get('/i/'.$m[1].'?e='.$m[2].'&a=report')->assertRedirect('/i/v/'.$inv->ulid.'?report=1#report');
        $this->get('/i/v/'.$inv->ulid.'?report=1')->assertOk()->assertSee('id="report"', false);
    }

    public function test_unsigned_contract_signer_is_reminded_and_audited(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $biz->update(['reminder_settings' => ['contract_unsigned' => ['enabled' => true, 'every_days' => 3]]]);
        $client = $this->makeClient($biz, 'Signer Co');
        $this->actingSystem($owner);
        $svc = app(ContractService::class);
        $c = $svc->create($biz, [
            'client_id' => $client->id, 'title' => 'Retainer', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'Advisory', 'fees' => '$1,000 / month', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']],
        ]);
        $svc->send($svc->prepare($c->currentVersion));

        $this->travel(2)->days();
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(0, EmailOutbox::where('kind', 'contract_reminder')->count());
        $this->travel(1)->days();
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(1, EmailOutbox::where('kind', 'contract_reminder')->count());
        $this->assertTrue(AuditEvent::where('action', 'contract.reminder_queued')->where('reason', 'like', '%awaiting signature%')->exists());

        // Signed recipients are no longer reminded.
        ContractRecipient::query()->update(['status' => 'signed']);
        $this->travel(5)->days();
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(1, EmailOutbox::where('kind', 'contract_reminder')->count());
    }

    public function test_reminders_are_off_by_default_and_saved_per_business(): void
    {
        [$owner, $biz] = $this->sentInvoice([]);
        $this->travel(60)->days();
        $this->assertSame(0, $this->reminders());

        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/reminders", [
            'contract_unsigned' => ['enabled' => '1', 'every_days' => 5],
            'invoice_unviewed' => ['enabled' => '0', 'every_days' => 3],
            'invoice_overdue' => ['enabled' => '1', 'every_days' => 3],
        ])->assertSessionHas('status');
        $biz->refresh();
        $this->assertSame(['enabled' => true, 'every_days' => 5], $biz->reminder('contract_unsigned'));
        $this->assertFalse($biz->reminder('invoice_unviewed')['enabled']);
        $this->assertTrue(AuditEvent::where('action', 'business.reminders_updated')->exists());
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings")->assertOk()->assertSee('Automatic client reminders');
    }

    public function test_per_invoice_toggle_overrides_business_setting(): void
    {
        [$owner, $biz, $inv] = $this->sentInvoice([]);
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()->assertSee('Automatic reminders off', false);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/auto-reminders")->assertSessionHas('status');
        $this->assertTrue($inv->fresh()->auto_reminders);
        $this->assertTrue(AuditEvent::where('action', 'invoice.auto_reminders_on')->exists());
        $this->travel(3)->days();
        $this->assertSame(1, $this->reminders());

        // Turned off on one invoice while the business has reminders on.
        $biz->update(['reminder_settings' => ['invoice_unviewed' => ['enabled' => true, 'every_days' => 1]]]);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/auto-reminders");
        $this->assertFalse($inv->fresh()->auto_reminders);
        $this->travel(3)->days();
        $this->assertSame(1, $this->reminders());
    }

    public function test_per_contract_toggle_overrides_business_setting(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz, 'Signer Co');
        $this->actingSystem($owner);
        $svc = app(ContractService::class);
        $c = $svc->create($biz, [
            'client_id' => $client->id, 'title' => 'Retainer', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'Advisory', 'fees' => '$1,000 / month', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']],
        ]);
        $svc->send($svc->prepare($c->currentVersion));
        $this->travel(3)->days();
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(0, EmailOutbox::where('kind', 'contract_reminder')->count());

        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}")->assertOk()->assertSee('click to turn on', false);
        $this->signIn($owner)->post("/b/{$biz->ulid}/contracts/{$c->id}/auto-reminders")->assertSessionHas('status');
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(1, EmailOutbox::where('kind', 'contract_reminder')->count());
        $this->assertTrue(AuditEvent::where('action', 'contract.auto_reminders_on')->exists());
    }

    public function test_payment_thank_you_links_paid_invoice_pdf(): void
    {
        [$owner, $biz, $inv] = $this->sentInvoice([]);
        app(\App\Services\Payments\PaymentService::class)->record($biz, [
            'client_id' => $inv->client_id, 'amount_cents' => 50000, 'received_date' => now()->toDateString(), 'method' => 'zelle', 'send_receipt' => true,
        ], [$inv->id => 50000]);
        app(OutboxProcessor::class)->run(10, microtime(true) + 10);
        $row = EmailOutbox::where('kind', 'receipt')->sole();
        $this->assertSame('client@example.test', $row->to_email);
        $this->assertSame($inv->id, $row->invoice_id);
        $mail = end(LogNotificationGateway::$sent);
        $this->assertStringContainsString('Thank you for your payment', $mail->subject);
        $this->assertStringContainsString('Download paid invoice', $mail->body);
        preg_match('#/i/([A-Za-z0-9_-]{43})\?d=paid&amp;e=([A-Za-z0-9_-]{43})&amp;a=paid_pdf#', $mail->body, $m);
        $this->assertNotEmpty($m);
        $this->assertTrue(AuditEvent::where('action', 'payment.receipt_queued')->exists());

        $this->flushSession();
        $this->get('/i/'.$m[1].'?d=paid&e='.$m[2].'&a=paid_pdf')->assertRedirect('/i/v/'.$inv->ulid.'/paid-pdf');
        $this->get('/i/v/'.$inv->ulid.'/paid-pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$inv->number.'-paid.pdf"');
        $this->assertTrue(DocumentAccessEvent::where('event', 'paid_pdf_downloaded')->exists());
        $this->assertTrue(DocumentAccessEvent::where('event', 'email_clicked')->where('detail', 'paid_pdf')->exists());
    }

    public function test_existing_contract_without_outbox_history_falls_back_to_sent_time(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $biz->update(['reminder_settings' => ['contract_unsigned' => ['enabled' => true, 'every_days' => 3]]]);
        $client = $this->makeClient($biz, 'Signer Co');
        $this->actingSystem($owner);
        $svc = app(ContractService::class);
        $c = $svc->create($biz, [
            'client_id' => $client->id, 'title' => 'Retainer', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'Advisory', 'fees' => '$1,000 / month', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']],
        ]);
        $svc->send($svc->prepare($c->currentVersion));
        EmailOutbox::query()->delete();
        $this->travel(3)->days();
        $this->artisan('finance:reminders')->assertSuccessful();
        $this->assertSame(1, EmailOutbox::where('kind', 'contract_reminder')->count());
    }

    public function test_mail_security_prescans_are_not_counted_as_opens_or_clicks(): void
    {
        config(['finance.tracking.prefetch_seconds' => 30]);
        Http::fake(fn ($r) => Http::response(['org' => str_contains($r->url(), '40.94.') ? 'AS8075 Microsoft Corporation' : 'AS7922 Comcast Cable', 'city' => 'X', 'country' => 'US']));
        [$owner, $biz, $inv] = $this->sentInvoice([]);
        $body = end(LogNotificationGateway::$sent)->body;
        preg_match('#/t/o/([A-Za-z0-9_-]{43})\.gif#', $body, $px);
        preg_match('#/i/([A-Za-z0-9_-]{43})\?e=([A-Za-z0-9_-]{43})&amp;a=view#', $body, $link);
        $this->flushSession();
        $browser = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36'];

        // Seconds after delivery: an automatic pre-fetch.
        $this->withHeaders($browser)->withServerVariables(['REMOTE_ADDR' => '73.1.2.3'])->get('/t/o/'.$px[1].'.gif')->assertOk();
        $this->travel(5)->minutes();
        // Microsoft-owned IP following the link: Defender pre-check, not a click.
        $this->withHeaders($browser)->withServerVariables(['REMOTE_ADDR' => '40.94.1.1'])->get('/i/'.$link[1].'?e='.$link[2].'&a=view');
        $this->flushSession();
        $this->travel(11)->minutes();
        // Apple Mail Privacy Protection preloads images with a bare user agent.
        $this->withHeader('User-Agent', 'Mozilla/5.0')->withServerVariables(['REMOTE_ADDR' => '73.1.2.3'])->get('/t/o/'.$px[1].'.gif');
        $row = EmailOutbox::first();
        $this->assertSame(0, $row->open_count);
        $this->assertSame(0, $row->click_count);
        $this->assertNull($row->opened_at);
        $this->assertSame(3, $row->scan_count);
        $this->assertSame(0, DocumentAccessEvent::where('probable_automation', false)->count());
        $reasons = DocumentAccessEvent::pluck('automation_reason')->implode('|');
        $this->assertStringContainsString('of delivery', $reasons);
        $this->assertStringContainsString('Microsoft email security', $reasons);
        $this->assertStringContainsString('Apple Mail Privacy Protection', $reasons);

        // A real person later.
        $this->travel(15)->minutes();
        $this->withHeaders($browser)->withServerVariables(['REMOTE_ADDR' => '73.1.2.3'])->get('/t/o/'.$px[1].'.gif');
        $this->assertSame(1, $row->fresh()->open_count);

        $this->flushSession();
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()
            ->assertSee('Email pre-scanned by mail security (not an open)')->assertSee('Link pre-checked by mail security (not a click)')
            ->assertSee('Pre-scanned')->assertSee('Opened the email');
    }

    public function test_next_pending_reminder_is_shown_in_activity(): void
    {
        [$owner, $biz, $inv] = $this->sentInvoice(['invoice_unviewed' => ['enabled' => true, 'every_days' => 3]]);
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()
            ->assertSee('Reminder scheduled (invoice not yet viewed)')->assertSee('every 3 days')->assertSee('pending');

        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/auto-reminders");
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()->assertDontSee('Reminder scheduled');

        // Agreements show the next signing reminder per signer.
        $biz->update(['reminder_settings' => ['contract_unsigned' => ['enabled' => true, 'every_days' => 4]]]);
        $client = $this->makeClient($biz, 'Signer Co');
        $svc = app(ContractService::class);
        $c = $svc->create($biz, [
            'client_id' => $client->id, 'title' => 'Retainer', 'template_id' => $biz->contractTemplates()->value('id'),
            'effective_date' => '2026-07-01', 'scope' => 'Advisory', 'fees' => '$1,000 / month', 'signing_order' => 'parallel',
            'signers' => [['name' => 'Pat Signer', 'email' => 'pat@example.test']],
        ]);
        $svc->send($svc->prepare($c->currentVersion));
        $this->signIn($owner)->get("/b/{$biz->ulid}/contracts/{$c->id}")->assertOk()
            ->assertSee('Signing reminder scheduled')->assertSee('every 4 days until signed');
    }

    public function test_client_interactions_are_audited_with_ip_location_and_source(): void
    {
        Http::fake(['ipinfo.io/*' => Http::response(['city' => 'Austin', 'region' => 'Texas', 'country' => 'US', 'org' => 'AS7922 Comcast Cable'])]);
        [$owner, $biz, $inv] = $this->sentInvoice([]);
        $body = end(LogNotificationGateway::$sent)->body;
        preg_match('#/t/o/([A-Za-z0-9_-]{43})\.gif#', $body, $px);
        preg_match('#/i/([A-Za-z0-9_-]{43})\?e=#', $body, $link);
        $this->flushSession();

        $this->withServerVariables(['REMOTE_ADDR' => '66.249.84.1'])->withHeader('User-Agent', 'Mozilla/5.0 via ggpht.com GoogleImageProxy')
            ->get('/t/o/'.$px[1].'.gif')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '73.1.2.3'])->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh)')->get('/i/'.$link[1]);
        $this->withServerVariables(['REMOTE_ADDR' => '73.1.2.3'])->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh)')->get('/i/v/'.$inv->ulid)->assertOk();

        $view = DocumentAccessEvent::where('event', 'invoice_viewed')->first();
        $this->assertSame('73.1.2.3', $view->ip);
        $this->assertSame('Austin, Texas, US', $view->location);
        $this->assertSame('AS7922 Comcast Cable', $view->network);

        $open = AuditEvent::where('action', 'client.email_opened')->first();
        $this->assertSame('client', $open->actor_type);
        $this->assertSame('Invoice', $open->object_type);
        $this->assertStringContainsString('Gmail image proxy', $open->after['source']);
        $this->assertSame('client@example.test', $open->after['email']);
        $this->assertSame('Client device', AuditEvent::where('action', 'client.invoice_viewed')->first()->after['source']);

        $this->flushSession();
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()->assertSee('Austin, Texas, US')->assertSee('Gmail image proxy', false);
    }
}
