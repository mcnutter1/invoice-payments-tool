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
