<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\RecurringSchedule;
use App\Models\ScheduleRun;
use App\Services\Recurring\RecurrenceCalculator;
use App\Services\Recurring\RecurringService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringTest extends TestCase
{
    private function schedule(array $over = [])
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $s = app(RecurringService::class)->save($biz, null, array_merge([
            'client_id' => $client->id, 'name' => 'Retainer', 'cadence' => 'month', 'interval' => 1,
            'start_date' => '2026-01-31', 'auto_issue' => true, 'auto_send' => false,
            'lines' => [['name' => 'Monthly retainer', 'quantity' => '1', 'unit_price' => '2000']],
        ], $over));

        return [$owner, $biz, $s];
    }

    public function test_arr_is_annualized_per_cadence_and_shown_on_client_dashboard_and_recurring_pages(): void
    {
        [$owner, $biz, $monthly] = $this->schedule(['start_date' => '2026-11-01']); // $2,000 / month
        $client = $monthly->client;
        $svc = app(RecurringService::class);
        $base = ['client_id' => $client->id, 'start_date' => '2026-11-01', 'auto_issue' => false];
        $svc->save($biz, null, $base + ['name' => 'Quarterly', 'cadence' => 'quarter', 'interval' => 1, 'lines' => [['name' => 'Review', 'quantity' => '2', 'unit_price' => '750']]]);
        $svc->save($biz, null, $base + ['name' => 'Biweekly', 'cadence' => 'week', 'interval' => 2, 'lines' => [['name' => 'Support', 'quantity' => '1', 'unit_price' => '100']]]);
        $paused = $svc->save($biz, null, $base + ['name' => 'Paused', 'cadence' => 'year', 'interval' => 1, 'lines' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '999']]]);
        $paused->update(['status' => 'paused']);
        $ended = $svc->save($biz, null, $base + ['name' => 'Ended', 'cadence' => 'month', 'interval' => 1, 'end_date' => '2026-11-15', 'lines' => [['name' => 'Y', 'quantity' => '1', 'unit_price' => '50']]]);
        $ended->update(['end_date' => '2020-01-01']);

        // 2,000×12 + 1,500×4 + 100×26 = 24,000 + 6,000 + 2,600
        $r = app(\App\Services\Recurring\RecurringRevenue::class)->summary($biz);
        $this->assertSame(3260000, $r['arr']);
        $this->assertSame(271667, $r['mrr']);
        $this->assertSame(3, $r['count']);
        $this->assertSame(99900, $r['by_schedule'][$paused->id]);

        $this->signIn($owner)->get("/b/{$biz->ulid}/clients/{$client->id}")->assertOk()->assertSee('Annual recurring revenue')->assertSee('$32,600.00')->assertSee('Lifetime billed');
        $this->signIn($owner)->get("/b/{$biz->ulid}")->assertOk()->assertSee('Annual recurring revenue')->assertSee('$32,600.00');
        $this->signIn($owner)->get("/b/{$biz->ulid}/recurring")->assertOk()->assertSee('$32,600.00')->assertSee('$24,000.00');
        $this->signIn($owner)->get("/b/{$biz->ulid}/recurring/new")->assertOk()->assertSee('+ Add service or product')->assertSee('data-lines-empty', false);
        $this->signIn($owner)->post("/b/{$biz->ulid}/recurring", ['name' => 'Empty', 'client_id' => $client->id, 'cadence' => 'month', 'interval' => 1, 'start_date' => '2026-11-01'])
            ->assertSessionHasErrors(['lines' => 'Add at least one service or product.']);
    }

    public function test_form_filters_agreements_by_client_and_picks_recipients_from_contacts(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $a = $this->makeClient($biz, 'Alpha Co');
        $b = $this->makeClient($biz, 'Beta Co');
        \App\Models\Contact::create(['business_id' => $biz->id, 'client_id' => $a->id, 'name' => 'Ann Billing', 'email' => 'ann@alpha.test', 'is_primary_billing' => true]);
        \App\Models\Contact::create(['business_id' => $biz->id, 'client_id' => $a->id, 'name' => 'Al Ops', 'email' => 'al@alpha.test']);
        $svc = app(\App\Services\Contracts\ContractService::class);
        $ca = $svc->create($biz, ['client_id' => $a->id, 'title' => 'Alpha MSA', 'body' => 'x', 'signing_order' => 'parallel']);
        $cb = $svc->create($biz, ['client_id' => $b->id, 'title' => 'Beta MSA', 'body' => 'x', 'signing_order' => 'parallel']);

        $html = $this->signIn($owner)->get("/b/{$biz->ulid}/recurring/new")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-client="'.$a->id.'"\s+>[^<]*Alpha MSA/', $html);
        $this->assertMatchesRegularExpression('/data-client="'.$b->id.'"\s+hidden disabled\s*>[^<]*Beta MSA/', $html);
        $this->assertStringContainsString('value="ann@alpha.test" checked', $html);
        $this->assertStringNotContainsString('value="al@alpha.test" checked', $html);

        // Agreement from another client is rejected server-side.
        $base = ['name' => 'R', 'client_id' => $a->id, 'cadence' => 'month', 'interval' => 1, 'start_date' => '2026-11-01', 'lines' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '10']]];
        $this->signIn($owner)->post("/b/{$biz->ulid}/recurring", $base + ['contract_id' => $cb->id])->assertSessionHas('error');
        $this->signIn($owner)->post("/b/{$biz->ulid}/recurring", $base + ['contract_id' => $ca->id, 'recipient_emails' => ['ann@alpha.test', 'al@alpha.test'], 'recipients' => 'boss@alpha.test'])->assertRedirect();
        $this->assertSame(['ann@alpha.test', 'al@alpha.test', 'boss@alpha.test'], RecurringSchedule::latest('id')->first()->recipients);
    }

    public function test_month_end_clamp_restores_anchor_and_handles_leap_year(): void
    {
        $s = new RecurringSchedule(['cadence' => 'month', 'interval' => 1, 'start_date' => Carbon::parse('2028-01-31')]);
        $calc = new RecurrenceCalculator;
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30'], array_map(fn ($n) => $calc->occurrence($s, $n)->toDateString(), [0, 1, 2, 3]));
        $s2 = new RecurringSchedule(['cadence' => 'year', 'interval' => 1, 'start_date' => Carbon::parse('2028-02-29')]);
        $this->assertSame('2029-02-28', $calc->occurrence($s2, 1)->toDateString());
        $this->assertSame('2032-02-29', $calc->occurrence($s2, 4)->toDateString());
    }

    public function test_concurrent_runs_produce_one_invoice_per_period(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        [, , $s] = $this->schedule();
        Carbon::setTestNow('2026-01-31 15:00:00');
        $svc = app(RecurringService::class);
        $svc->runOne($s->id);
        $svc->runOne($s->id);
        $svc->runDue(microtime(true) + 5);
        $this->assertSame(1, Invoice::where('recurring_schedule_id', $s->id)->count());
        $this->assertSame('issued', Invoice::first()->status);
        $this->assertSame('2026-02-28', $s->fresh()->next_run_date->toDateString());
        // Unique key is the backstop against a racing insert.
        $this->expectException(\Illuminate\Database\QueryException::class);
        ScheduleRun::create(['business_id' => $s->business_id, 'recurring_schedule_id' => $s->id, 'billing_period_key' => '2026-01-31', 'period_date' => '2026-01-31', 'status' => 'generated']);
    }

    public function test_cron_outage_generates_only_latest_and_queues_review(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        [, , $s] = $this->schedule(['start_date' => '2026-01-15']);
        Carbon::setTestNow('2026-04-20 09:00:00');
        app(RecurringService::class)->runOne($s->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('2026-04-15', Invoice::first()->issue_date->toDateString());
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], ScheduleRun::where('status', 'needs_review')->orderBy('period_date')->pluck('billing_period_key')->all());
        $draft = app(RecurringService::class)->generateReviewed(ScheduleRun::where('status', 'needs_review')->first());
        $this->assertSame('draft', $draft->status);
    }

    public function test_unsigned_agreement_pauses_and_paused_schedule_does_not_run(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz);
        $contract = app(\App\Services\Contracts\ContractService::class)->create($biz, ['client_id' => $client->id, 'title' => 'T', 'signing_order' => 'parallel', 'body' => 'x']);
        $s = app(RecurringService::class)->save($biz, null, ['client_id' => $client->id, 'contract_id' => $contract->id, 'name' => 'R', 'cadence' => 'month', 'interval' => 1,
            'start_date' => '2026-01-15', 'auto_issue' => true, 'require_signed_contract' => true, 'lines' => [['name' => 'x', 'quantity' => '1', 'unit_price' => '10']]]);
        Carbon::setTestNow('2026-01-16 09:00:00');
        app(RecurringService::class)->runOne($s->id);
        $this->assertSame('paused', $s->fresh()->status);
        $this->assertSame(0, Invoice::count());
    }
}
