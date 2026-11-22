<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ChartAccount;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Payments\PaymentService;
use Tests\TestCase;

class DataApiTest extends TestCase
{
    private function books(): array
    {
        $owner = $this->makeUser();
        $b = $this->makeBusiness($owner);
        $client = $this->makeClient($b, 'Acme Widgets');
        $this->actingSystem($owner);
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($b, null, ['client_id' => $client->id, 'lines' => [['name' => 'Consulting', 'quantity' => '2', 'unit_price' => '600.25']]]));
        $svc->issue($svc->saveDraft($b, null, ['client_id' => $client->id, 'lines' => [['name' => 'Support', 'quantity' => '1', 'unit_price' => '100']]]));
        $svc->saveDraft($b, null, ['client_id' => $client->id, 'lines' => [['name' => 'Draft only', 'quantity' => '1', 'unit_price' => '5']]]);
        app(PaymentService::class)->record($b, ['client_id' => $client->id, 'method' => 'zelle', 'amount_cents' => 120050, 'received_date' => now()->toDateString()], [$inv->id => 120050]);
        app(ExpenseService::class)->create($b, ['kind' => 'expense', 'expense_date' => now()->toDateString(), 'payment_account_id' => $b->account('bank')->id,
            'lines' => [['account_id' => ChartAccount::where('business_id', $b->id)->where('code', '6400')->value('id'), 'amount_cents' => 4510]]]);

        return [$owner, $b, $client, $inv];
    }

    private function api(string $path, string $token, array $query = [])
    {
        return $this->withHeaders(['X-API-Key' => $token])->getJson('/api/v1/data'.$path.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_settings_creates_key_shown_once_and_revocation_is_immediate(): void
    {
        [$owner, $b] = $this->books();
        $this->signIn($owner)->post("/b/{$b->ulid}/settings/api-keys", ['name' => 'McNutt Legacy', 'scopes' => ['ledger.read', 'invoices.read']])
            ->assertRedirect()->assertSessionHas('api_key_token');
        $token = session('api_key_token');
        $key = ApiKey::firstOrFail();
        $this->assertStringStartsWith('fin_', $token);
        $this->assertSame(hash('sha256', $token), $key->token_hash);
        $this->assertSame(['ledger.read', 'invoices.read'], $key->scopes);
        $this->signIn($owner)->get("/b/{$b->ulid}/settings")->assertOk()->assertSee('Data API access')->assertSee($key->prefix)->assertSee($token);
        $this->signIn($owner)->get("/b/{$b->ulid}/settings")->assertOk()->assertDontSee($token);

        $this->api('', $token)->assertOk()->assertJsonPath('data.business.id', $b->ulid)->assertJsonPath('data.key.scopes', ['ledger.read', 'invoices.read']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v1/data/accounts')->assertOk();
        $this->api('/payments', $token)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $this->signIn($owner)->post("/b/{$b->ulid}/settings/api-keys/{$key->id}/revoke")->assertRedirect();
        $this->api('', $token)->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
        $this->getJson('/api/v1/data')->assertUnauthorized();
        $this->api('', 'fin_not-a-real-key')->assertUnauthorized();
    }

    public function test_datasets_return_decimal_money_stable_ids_and_only_this_business(): void
    {
        [, $b, $client, $inv] = $this->books();
        [, $token] = ApiKey::issue($b, 'Legacy', array_keys(ApiKey::SCOPES), null, null);
        $other = $this->makeBusiness($this->makeUser('u_other'), 'Other Co');
        $otherClient = $this->makeClient($other, 'Secret Client');
        $svc = app(InvoiceService::class);
        $svc->issue($svc->saveDraft($other, null, ['client_id' => $otherClient->id, 'lines' => [['name' => 'Hidden', 'quantity' => '1', 'unit_price' => '999']]]));

        $invoices = $this->api('/invoices', $token)->assertOk()->assertJsonPath('meta.business_id', $b->ulid)->json('data');
        $this->assertCount(2, $invoices); // drafts excluded by default
        $first = collect($invoices)->firstWhere('ulid', $inv->ulid);
        $this->assertSame(['1200.50', '1200.50', '0.00', 'paid', 'Acme Widgets'], [$first['total'], $first['paid'], $first['balance'], $first['settlement_status'], $first['client_name']]);
        $this->assertSame(['2', '600.25', '1200.50'], [$first['lines'][0]['quantity'], $first['lines'][0]['unit_price'], $first['lines'][0]['amount']]);
        $this->assertCount(3, $this->api('/invoices', $token, ['include_drafts' => 1])->json('data'));
        $this->assertStringNotContainsString('Secret Client', $this->api('/clients', $token)->getContent());

        $pay = $this->api('/payments', $token)->assertOk()->json('data.0');
        $this->assertSame(['1200.50', '0.00', '1200.50', (string) $inv->id], [$pay['amount'], $pay['fee'], $pay['net'], $pay['allocations'][0]['invoice_id']]);

        $exp = $this->api('/expenses', $token)->assertOk()->json('data.0');
        $this->assertSame(['45.10', '6400'], [$exp['total'], $exp['lines'][0]['account_code']]);

        $entries = $this->api('/journal-entries', $token, ['limit' => 500])->assertOk()->json('data');
        foreach ($entries as $e) {
            $dr = array_sum(array_map(fn ($l) => (float) $l['debit'], $e['lines']));
            $cr = array_sum(array_map(fn ($l) => (float) $l['credit'], $e['lines']));
            $this->assertEqualsWithDelta($dr, $cr, 0.001, 'entry '.$e['id'].' balances');
        }
        $this->assertContains('invoice', array_column(array_column($entries, 'source'), 'type'));
        $this->api('/cash-events', $token)->assertOk();
        $this->api('/vendors', $token)->assertOk();
        $this->api('/credit-notes', $token)->assertOk()->assertJsonPath('data', []);
        $this->api('/deletions', $token)->assertOk()->assertJsonPath('data', []);
    }

    public function test_cursor_pagination_and_incremental_sync(): void
    {
        [, $b] = $this->books();
        [, $token] = ApiKey::issue($b, 'Legacy', ['ledger.read', 'invoices.read'], null, null);

        $seen = [];
        $cursor = null;
        do {
            $r = $this->api('/journal-entries', $token, array_filter(['limit' => 1, 'cursor' => $cursor]))->assertOk();
            $seen = array_merge($seen, array_column($r->json('data'), 'id'));
            $cursor = $r->json('meta.next_cursor');
        } while ($r->json('meta.has_more'));
        $this->assertSame(\App\Models\JournalEntry::where('business_id', $b->id)->orderBy('id')->pluck('id')->map(fn ($i) => (string) $i)->all(), $seen);

        $page1 = $this->api('/invoices', $token, ['limit' => 1])->assertOk();
        $page2 = $this->api('/invoices', $token, ['limit' => 1, 'cursor' => $page1->json('meta.next_cursor')])->assertOk();
        $this->assertNotSame($page1->json('data.0.id'), $page2->json('data.0.id'));
        $this->assertFalse($page2->json('meta.has_more'));

        $this->travel(10)->minutes();
        $since = now()->toIso8601String();
        $this->assertSame([], $this->api('/invoices', $token, ['updated_since' => $since])->json('data'));
        $this->api('/invoices', $token, ['cursor' => 'garbage'])->assertStatus(400)->assertJsonPath('error.code', 'invalid_cursor');
        $this->api('/invoices', $token, ['limit' => 5000])->assertStatus(422);
    }

    public function test_reports_and_expired_keys(): void
    {
        [, $b] = $this->books();
        [, $token] = ApiKey::issue($b, 'Legacy', ['reports.read'], null, null);
        $from = now()->startOfYear()->toDateString();
        $to = now()->endOfYear()->toDateString();

        $pnl = $this->api('/reports/profit-and-loss', $token, ['from' => $from, 'to' => $to, 'basis' => 'accrual'])->assertOk()->json('data');
        $this->assertSame('1300.50', $pnl['totals'][0]['income']);
        $cash = $this->api('/reports/profit-and-loss', $token, ['from' => $from, 'to' => $to, 'basis' => 'cash'])->assertOk()->json('data');
        $this->assertSame('1200.50', $cash['totals'][0]['income']);
        $this->api('/reports/balance-sheet', $token, ['as_of' => $to])->assertOk()->assertJsonPath('data.balanced', true);
        $tb = $this->api('/reports/trial-balance', $token, ['as_of' => $to])->assertOk()->json('data');
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
        $this->api('/reports/tax-estimate', $token, ['year' => now()->year])->assertOk()->assertJsonPath('meta.estimate', true)->assertJsonStructure(['data' => ['summary' => ['business_tax'], 'settlement' => ['federal' => ['balance']]]]);
        $this->api('/reports/profit-and-loss', $token)->assertStatus(422);
        $this->api('/invoices', $token)->assertForbidden();

        [, $expired] = ApiKey::issue($b, 'Old', ['reports.read'], now()->addDay(), null);
        $this->api('', $expired)->assertOk();
        $this->travel(2)->days();
        $this->api('', $expired)->assertUnauthorized();
    }
}
