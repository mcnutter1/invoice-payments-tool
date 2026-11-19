<?php

namespace Tests\Feature;

use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentClaim;
use App\Services\Invoicing\InvoiceService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\StripeGateway;
use App\Services\PublicAccess\PublicLinkService;
use App\Services\Recurring\RecurringService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    private function setup3(): array
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);

        return [$owner, $biz, $client];
    }

    private function issue($biz, $client, array $methods, string $price = '1000', array $extra = []): Invoice
    {
        $svc = app(InvoiceService::class);

        return $svc->issue($svc->saveDraft($biz->fresh(), null, ['client_id' => $client->id, 'payment_methods' => $methods,
            'lines' => [['name' => 'Consulting', 'quantity' => '1', 'unit_price' => $price]]] + $extra));
    }

    private function openAsClient(Invoice $inv): void
    {
        [, $raw] = app(PublicLinkService::class)->issue($inv->business, 'invoice_view', [
            'invoice_id' => $inv->id, 'allowed_actions' => ['view', 'pdf', 'report_payment'], 'recipient_email' => 'clientco@example.test',
        ], 30);
        $this->flushSession();
        $this->get('/i/'.$raw)->assertRedirect();
    }

    private function saveAch($owner, $biz, array $extra = [])
    {
        return $this->signIn($owner)->post("/b/{$biz->ulid}/settings/payments", array_merge([
            'payment_methods' => ['ach', 'zelle', 'check'], 'zelle_recipient_name' => 'Acme', 'zelle_email' => 'pay@example.test',
            'check_payee' => 'Acme LLC', 'check_address' => '1 Main St',
            'ach_routing_number' => '021000021', 'ach_account_number' => '123456789012', 'ach_account_type' => 'checking',
            'ach_account_name' => 'Acme Consulting LLC', 'confirm_change' => 1,
        ], $extra));
    }

    private function connectStripe($owner, $biz): void
    {
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/stripe", ['stripe_secret_key' => 'sk_test_'.str_repeat('a', 24), 'stripe_webhook_secret' => 'whsec_testsecret'])
            ->assertSessionHas('status');
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/payments", [
            'payment_methods' => ['card', 'zelle'], 'zelle_recipient_name' => 'Acme', 'zelle_email' => 'pay@example.test', 'confirm_change' => 1,
        ])->assertSessionHas('status');
    }

    private function intent(int $invoiceId, string $id = 'pi_123', int $amount = 100000, int $fee = 2930, array $meta = []): array
    {
        return [
            'id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount_received' => $amount, 'currency' => 'usd', 'created' => time(),
            'metadata' => ['invoice_id' => (string) $invoiceId] + $meta,
            'payment_method' => ['id' => 'pm_card_visa', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            'latest_charge' => ['id' => 'ch_1', 'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']], 'balance_transaction' => ['id' => 'txn_1', 'fee' => $fee]],
        ];
    }

    private function fakeStripe(\Closure $routes): void
    {
        Http::fake(function (HttpRequest $r) use ($routes) {
            if (str_contains($r->url(), 'bankrouting.io')) return Http::response(['status' => 'success', 'data' => ['bank_name' => 'JPMORGAN CHASE BANK, NA', 'city' => 'NEW YORK', 'state' => 'NY']]);
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/account') return Http::response(['id' => 'acct_1', 'settings' => ['dashboard' => ['display_name' => 'Acme Stripe']]]);

            return $routes($r, $path) ?? Http::response(['error' => ['message' => 'unexpected '.$r->method().' '.$path]], 400);
        });
    }

    public function test_ach_instructions_lookup_bank_encrypt_account_and_show_to_client(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $this->fakeStripe(fn () => null);
        $this->saveAch($owner, $biz)->assertSessionHas('status');
        $biz->refresh();
        $this->assertSame('Jpmorgan Chase Bank, NA', $biz->ach_bank_name);
        $this->assertSame('New York, NY', $biz->ach_bank_location);
        $this->assertSame('123456789012', $biz->achAccountNumber());
        $this->assertFalse(DB::table('businesses')->where('id', $biz->id)->where('ach_account_number_encrypted', 'like', '%123456789012%')->exists());

        $this->signIn($owner)->getJson("/b/{$biz->ulid}/settings/bank-lookup?routing=021000021")->assertOk()->assertJson(['valid' => true, 'bank_name' => 'Jpmorgan Chase Bank, NA']);
        $this->signIn($owner)->getJson("/b/{$biz->ulid}/settings/bank-lookup?routing=021000022")->assertOk()->assertJson(['valid' => false]);
        $this->signIn($owner)->getJson("/b/{$biz->ulid}/settings/bank-lookup?swift=CHASUS33")->assertOk()->assertJson(['valid' => true, 'bank_name' => 'JPMorgan Chase Bank, N.A.']);
        $this->saveAch($owner, $biz, ['ach_routing_number' => '123456789'])->assertSessionHasErrors('ach_routing_number');

        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['ach', 'check']);
        $this->assertSame(['ach', 'check'], $inv->instructions_snapshot['methods']);
        $this->assertArrayNotHasKey('account_number', $inv->instructions_snapshot['ach']);
        $this->assertStringNotContainsString('123456789012', json_encode($inv->instructions_snapshot));

        $this->openAsClient($inv);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Bank transfer (ACH / wire)')->assertSee('Jpmorgan Chase Bank, NA')
            ->assertSee('021000021')->assertSee('123456789012')->assertSee('I’ve sent this payment', false)->assertDontSee('Pay by card');
        $this->post('/i/v/'.$inv->ulid.'/report-payment', ['method' => 'ach', 'amount' => '1000.00', 'reported_date' => now()->toDateString(), 'reference' => 'TRACE 42'])->assertRedirect();
        $this->assertSame('ach', PaymentClaim::first()->method);
        $this->get('/i/v/'.$inv->ulid)->assertSee('You reported a payment of $1,000.00 by ACH / wire transfer', false);
    }

    public function test_routing_numbers_missing_from_directory_use_known_fallback(): void
    {
        Http::fake(['bankrouting.io/*' => Http::response(['status' => 'error', 'error' => ['code' => 'BANK_NOT_FOUND']], 404)]);
        $banks = app(\App\Services\Payments\BankDirectory::class);
        $this->assertSame(['bank_name' => 'JPMorgan Chase Bank, N.A.', 'location' => 'NJ'], $banks->routing('021202337'));
        $this->assertNull($banks->routing('021000021'));
    }

    public function test_sent_invoice_can_add_newly_enabled_payment_methods(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $this->fakeStripe(fn () => null);
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['zelle', 'check']);
        $this->assertNotContains('ach', $inv->instructions_snapshot['methods']);

        $this->saveAch($owner, $biz)->assertSessionHas('status');
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices/{$inv->id}")->assertOk()->assertSee('1 new available');

        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/payment-methods", ['payment_methods' => ['ach', 'zelle', 'check', 'card']])
            ->assertSessionHas('status');
        $inv->refresh();
        $this->assertSame(['ach', 'zelle', 'check'], $inv->instructions_snapshot['methods']);
        $this->assertSame('payment options updated', $inv->latestDocument()->reason);
        $this->assertTrue(\App\Models\AuditEvent::where('action', 'invoice.payment_methods_updated')->exists());

        $this->openAsClient($inv);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Bank transfer (ACH / wire)')->assertSee('123456789012');

        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/payment-methods", ['payment_methods' => ['card']])
            ->assertSessionHas('error');
        $this->actingSystem($owner);
        app(PaymentService::class)->record($biz, ['client_id' => $client->id, 'method' => 'check', 'amount_cents' => 100000, 'received_date' => now()->toDateString()], [$inv->id => 100000]);
        $this->signIn($owner)->post("/b/{$biz->ulid}/invoices/{$inv->id}/payment-methods", ['payment_methods' => ['zelle']])
            ->assertSessionHas('error');
    }

    public function test_client_level_methods_limit_invoice_options(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $this->fakeStripe(fn () => null);
        $this->saveAch($owner, $biz);
        $this->signIn($owner)->post("/b/{$biz->ulid}/clients/{$client->id}", ['type' => 'company', 'display_name' => $client->display_name, 'payment_methods_custom' => 1, 'payment_methods' => ['check']])
            ->assertRedirect();
        $this->assertSame(['check'], $client->fresh()->payment_methods);

        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client->fresh(), ['ach', 'check']);
        $this->assertSame(['check'], $inv->payment_methods);
        $this->assertSame(['check'], $inv->instructions_snapshot['methods']);
        $this->assertNull($inv->instructions_snapshot['ach']);

        $this->signIn($owner)->post("/b/{$biz->ulid}/clients/{$client->id}", ['type' => 'company', 'display_name' => $client->display_name, 'payment_methods_custom' => 0]);
        $this->assertNull($client->fresh()->payment_methods);
    }

    public function test_fees_hit_merchant_fees_and_net_cash_for_record_deposit_add_fee_and_reverse(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['check']);
        $fees = $biz->account('merchant_fees');
        $bank = $biz->account('bank');
        $svc = app(PaymentService::class);

        $p = $svc->record($biz, ['client_id' => $client->id, 'method' => 'ach', 'amount_cents' => 100000, 'fee_cents' => 1500, 'received_date' => now()->toDateString(), 'send_receipt' => true], [$inv->id => 100000]);
        $this->assertSame(0, $inv->fresh()->balance_cents);
        $lines = JournalLine::where('journal_entry_id', $p->journal_entry_id)->get();
        $this->assertSame(98500, (int) $lines->where('account_id', $bank->id)->sum('debit_cents'));
        $this->assertSame(1500, (int) $lines->where('account_id', $fees->id)->sum('debit_cents'));
        $this->assertSame(1, EmailOutbox::where('kind', 'receipt')->where('payment_id', $p->id)->count());

        $svc->addFee($p, 500, now()->toDateString(), 'Incoming wire fee');
        $this->assertSame(2000, $p->fresh()->fee_cents);
        $this->assertSame(2000, (int) JournalLine::where('account_id', $fees->id)->sum(DB::raw('debit_cents - credit_cents')));

        $svc->reverse($p->fresh(), 'error', 'test');
        $this->assertSame(0, (int) JournalLine::where('account_id', $fees->id)->sum(DB::raw('debit_cents - credit_cents')));
        $this->assertSame(0, (int) JournalLine::where('account_id', $bank->id)->sum(DB::raw('debit_cents - credit_cents')));
        $this->assertSame(100000, $inv->fresh()->balance_cents);

        // Checks with a fee deposit the net amount from Undeposited Funds.
        $chk = $svc->record($biz, ['client_id' => $client->id, 'method' => 'check', 'amount_cents' => 100000, 'fee_cents' => 300, 'received_date' => now()->toDateString()], [$inv->id => 100000]);
        $svc->deposit($chk, $bank, now()->toDateString());
        $this->assertSame(0, (int) JournalLine::where('account_id', $biz->account('undeposited')->id)->sum(DB::raw('debit_cents - credit_cents')));

        $this->expectException(\App\Exceptions\DomainException::class);
        $svc->record($biz, ['client_id' => $client->id, 'method' => 'ach', 'amount_cents' => 100, 'fee_cents' => 100, 'received_date' => now()->toDateString()]);
    }

    public function test_claim_confirmation_records_fee_and_sends_thank_you(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $biz->update(['venmo_handle' => 'AcmeBiz', 'venmo_fee_percent' => 1.9, 'venmo_fee_fixed_cents' => 10, 'payment_methods' => ['venmo', 'check']]);
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['venmo']);
        $claim = new PaymentClaim(['invoice_id' => $inv->id, 'method' => 'venmo', 'reported_amount_cents' => 100000, 'reported_date' => now()->toDateString()]);
        $claim->business_id = $biz->id;
        $claim->save();

        $this->signIn($owner)->get("/b/{$biz->ulid}/claims/{$claim->id}")->assertOk()->assertSee('value="19.10"', false)->assertSee('Venmo business profile');
        $this->signIn($owner)->post("/b/{$biz->ulid}/claims/{$claim->id}/confirm", ['amount' => '1000', 'fee' => '19.10', 'received_date' => now()->toDateString(), 'evidence_checked' => 1, 'send_receipt' => 1])
            ->assertRedirect();
        $p = Payment::firstOrFail();
        $this->assertSame(1910, $p->fee_cents);
        $this->assertSame(0, $inv->fresh()->balance_cents);
        $this->assertSame(1, EmailOutbox::where('kind', 'receipt')->count());
    }

    public function test_venmo_feed_matches_by_note_records_or_queues_and_is_idempotent(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $biz->update(['venmo_handle' => 'AcmeBiz', 'venmo_fee_percent' => 1.9, 'venmo_fee_fixed_cents' => 10, 'payment_methods' => ['venmo', 'zelle']]);
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['venmo']);
        $inv2 = $this->issue($biz, $client, ['venmo'], '200');

        $this->openAsClient($inv);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('@AcmeBiz')->assertSee('https://venmo.com/AcmeBiz?txn=pay&amp;amount=1000.00', false);

        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/venmo-token")->assertSessionHas('venmo_token');
        $token = session('venmo_token');
        $this->flushSession();
        $api = fn (array $body, ?string $t = null) => $this->withHeader('Authorization', 'Bearer '.($t ?? $token))->postJson('/api/v1/venmo/payments', $body);

        $api(['transaction_id' => '1', 'amount' => '1'], 'vnm_wrong')->assertStatus(401);
        $api(['transaction_id' => '777', 'amount' => '55.00', 'note' => 'thanks!'])->assertStatus(202)->assertJson(['status' => 'unmatched']);

        // Manual review by default.
        $api(['transaction_id' => '1001', 'amount' => '1000.00', 'note' => 'for '.strtolower($inv->number), 'sender' => 'Jane'])->assertStatus(201)->assertJson(['status' => 'pending_review', 'invoice' => $inv->number]);
        $claim = PaymentClaim::where('external_id', 'venmo:1001')->firstOrFail();
        $this->assertSame('venmo_api', $claim->source);
        $this->assertSame(0, Payment::count());
        $api(['transaction_id' => '1001', 'amount' => '1000.00', 'note' => $inv->number])->assertOk()->assertJson(['status' => 'duplicate']);

        // Auto-confirm: recorded with the configured fee and a thank-you email.
        $biz->update(['venmo_auto_confirm' => true]);
        $api(['transaction_id' => '1002', 'amount' => '200.00', 'note' => 'Invoice '.$inv2->number])->assertStatus(201)->assertJson(['status' => 'recorded']);
        $p = Payment::where('external_id', 'venmo:1002')->firstOrFail();
        $this->assertSame('venmo', $p->method);
        $this->assertSame('venmo_api', $p->processor);
        $this->assertSame(390, $p->fee_cents); // 1.9% of $200 + $0.10
        $this->assertSame(0, $inv2->fresh()->balance_cents);
        $this->assertSame(1, EmailOutbox::where('kind', 'receipt')->where('payment_id', $p->id)->count());
    }

    public function test_stripe_checkout_records_card_payment_with_fee_and_receipt(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $sessions = [];
        $this->fakeStripe(function (HttpRequest $r, string $path) use (&$sessions) {
            if ($r->method() === 'POST' && $path === '/v1/checkout/sessions') {
                $sessions[] = $r->data();

                return Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);
            }
            if ($r->method() === 'GET' && $path === '/v1/checkout/sessions/cs_test_1') {
                $invId = (int) $sessions[0]['metadata']['invoice_id'];

                return Http::response(['id' => 'cs_test_1', 'payment_status' => 'paid', 'metadata' => $sessions[0]['metadata'], 'payment_intent' => $this->intent($invId)]);
            }
        });
        $this->connectStripe($owner, $biz);
        $this->assertSame('Acme Stripe', $biz->fresh()->stripe_account_label);
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['card', 'zelle']);

        $this->openAsClient($inv);
        $this->get('/i/v/'.$inv->ulid)->assertOk()->assertSee('Pay $1,000.00 by card');
        $this->post('/i/v/'.$inv->ulid.'/card')->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $this->assertSame(100000, (int) $sessions[0]['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame((string) $inv->id, $sessions[0]['payment_intent_data']['metadata']['invoice_id']);

        $this->get('/i/v/'.$inv->ulid.'/card/return?session_id=cs_test_1')->assertRedirect('/i/v/'.$inv->ulid)->assertSessionHas('status');
        $this->get('/i/v/'.$inv->ulid.'/card/return?session_id=cs_test_1'); // reload is idempotent
        $p = Payment::sole();
        $this->assertSame(['card', 'stripe', 'stripe:pi_123', 2930, 'Visa •••• 4242'], [$p->method, $p->processor, $p->external_id, $p->fee_cents, $p->reference]);
        $this->assertSame(0, $inv->fresh()->balance_cents);
        $this->assertSame(2930, (int) JournalLine::where('account_id', $biz->account('merchant_fees')->id)->sum('debit_cents'));
        $this->assertSame(1, EmailOutbox::where('kind', 'receipt')->count());
    }

    public function test_stripe_webhook_requires_valid_signature_and_dedupes(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $this->fakeStripe(function (HttpRequest $r, string $path) {
            if ($r->method() === 'GET' && str_starts_with($path, '/v1/payment_intents/pi_wh')) {
                return Http::response($this->intent((int) Invoice::first()->id, 'pi_wh', 100000, 3000));
            }
        });
        $this->connectStripe($owner, $biz);
        $this->actingSystem($owner);
        $inv = $this->issue($biz, $client, ['card']);
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_wh', 'metadata' => ['invoice_id' => (string) $inv->id]]]]);
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_testsecret');
        $send = fn (string $s) => $this->call('POST', '/webhooks/stripe/'.$biz->ulid, [], [], [], ['HTTP_STRIPE_SIGNATURE' => $s, 'CONTENT_TYPE' => 'application/json'], $payload);

        $send('t='.$t.',v1=deadbeef')->assertStatus(400);
        $this->assertTrue(StripeGateway::verifySignature($payload, $sig, 'whsec_testsecret'));
        $send($sig)->assertOk()->assertJson(['result' => 'recorded']);
        $send($sig)->assertOk()->assertJson(['result' => 'duplicate']);
        $this->assertSame(3000, Payment::sole()->fee_cents);
        $this->assertSame(0, $inv->fresh()->balance_cents);
    }

    public function test_recurring_card_autopay_charges_saved_card_on_issue(): void
    {
        [$owner, $biz, $client] = $this->setup3();
        $charged = [];
        $this->fakeStripe(function (HttpRequest $r, string $path) use (&$charged) {
            if ($r->method() === 'POST' && $path === '/v1/payment_intents') {
                $charged[] = $r->data();

                return Http::response($this->intent((int) $r->data()['metadata']['invoice_id'], 'pi_auto', (int) $r->data()['amount'], 1500, ['autopay' => '1']));
            }
        });
        $this->connectStripe($owner, $biz);
        $this->actingSystem($owner);
        $client->update(['stripe_customer_id' => 'cus_1']);
        $s = app(RecurringService::class)->save($biz->fresh(), null, [
            'client_id' => $client->id, 'name' => 'Monthly support', 'cadence' => 'month', 'interval' => 1, 'start_date' => $biz->localToday()->toDateString(),
            'auto_issue' => true, 'auto_send' => true, 'payment_methods' => ['card'], 'card_autopay' => true,
            'lines' => [['name' => 'Support', 'quantity' => '1', 'unit_price' => '500']],
        ]);
        $this->assertTrue($s->card_autopay);
        $s->forceFill(['stripe_payment_method_id' => 'pm_card_visa', 'autopay_card_label' => 'Visa •••• 4242'])->save();

        app(RecurringService::class)->runOne($s->id);
        $inv = Invoice::where('recurring_schedule_id', $s->id)->sole();
        $this->assertCount(1, $charged);
        $this->assertSame(['cus_1', 'pm_card_visa', 'true'], [$charged[0]['customer'], $charged[0]['payment_method'], $charged[0]['off_session']]);
        $this->assertSame(0, $inv->fresh()->balance_cents);
        $this->assertSame(1500, Payment::sole()->fee_cents);
        $this->assertSame(0, EmailOutbox::where('kind', 'invoice')->count()); // paid, so only the receipt goes out
        $this->assertSame(1, EmailOutbox::where('kind', 'receipt')->count());

        $this->signIn($owner)->get("/b/{$biz->ulid}/recurring/{$s->id}")->assertOk()->assertSee('Visa •••• 4242', false);
    }

    public function test_settings_page_renders_all_methods(): void
    {
        [$owner, $biz] = $this->setup3();
        $this->signIn($owner)->get("/b/{$biz->ulid}/settings")->assertOk()
            ->assertSee('ACH / wire transfer')->assertSee('Venmo business username')->assertSee('Connect Stripe')->assertSee('/webhooks/stripe/'.$biz->ulid);
        $this->signIn($owner)->post("/b/{$biz->ulid}/settings/payments", ['payment_methods' => ['card'], 'confirm_change' => 1])->assertSessionHas('error');
    }
}
