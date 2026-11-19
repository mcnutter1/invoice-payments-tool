<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ImportBatch;
use App\Services\Expenses\ExpenseImportService;
use App\Services\Expenses\Subscriptions\SubscriptionChargeImporter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionImportTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sample_sources_bill_every_30_days_with_realistic_amounts(): void
    {
        Carbon::setTestNow('2026-11-19 15:00:00');
        $owner = $this->makeUser();
        $b = $this->makeBusiness($owner);
        $sources = app(SubscriptionChargeImporter::class)->sources();
        $this->assertSame(['aws', 'm365', 'hiscox'], array_keys($sources));
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-12-31');

        $aws = $sources['aws']->charges($b, $from, $to);
        $this->assertGreaterThanOrEqual(10, count($aws));
        foreach ($aws as $i => $c) {
            $this->assertGreaterThanOrEqual(24566, $c->amountCents);
            $this->assertLessThanOrEqual(30588, $c->amountCents);
            $this->assertSame($c->amountCents, array_sum(array_column($c->lines, 'amount_cents')));
            $this->assertTrue($c->date->lte(Carbon::parse('2026-11-19')), 'no future charges');
            if ($i) $this->assertSame(30, (int) $aws[$i - 1]->date->diffInDays($c->date));
            $this->assertMatchesRegularExpression('/^sub:aws:\d{10}$/', $c->externalId);
        }
        $this->assertGreaterThan(1, count(array_unique(array_map(fn ($c) => $c->amountCents, $aws))));
        // Deterministic: a second pull returns the same charges.
        $this->assertSame(array_map(fn ($c) => $c->externalId, $aws), array_map(fn ($c) => $c->externalId, $sources['aws']->charges($b, $from, $to)));

        $m365 = $sources['m365']->charges($b, $from, $to)[0];
        $this->assertSame(10920, $m365->amountCents); // 2×$22 + 2×$30 + 5% monthly billing
        $this->assertSame([4400, 6000, 520], array_column($m365->lines, 'amount_cents'));
        $this->assertStringStartsWith('MSFT * E0', $m365->statement);
        $this->assertSame(8867, $sources['hiscox']->charges($b, $from, $to)[0]->amountCents);

        // Sources bill on different days of the cycle.
        $firstDays = array_map(fn ($s) => $s->charges($b, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-30'))[0]->date->day, $sources);
        $this->assertCount(3, array_unique($firstDays));
    }

    public function test_pull_review_import_dedupe_and_undo(): void
    {
        Carbon::setTestNow('2026-11-19 15:00:00');
        $owner = $this->makeUser();
        $b = $this->makeBusiness($owner);
        $bu = '/b/'.$b->ulid;

        $this->signIn($owner)->get($bu.'/expenses/import')->assertOk()->assertSee('Import subscription charges')->assertSee('Amazon Web Services')->assertSee('Next charge');
        $res = $this->signIn($owner)->post($bu.'/expenses/import/subscriptions', ['sources' => ['aws', 'm365', 'hiscox'], 'since' => '90']);
        $review = $res->assertRedirect()->headers->get('Location');
        $this->signIn($owner)->get($review)->assertOk()->assertSee('Subscriptions: Amazon Web Services, Microsoft 365, Hiscox Business Insurance')
            ->assertSee('Amazon Elastic Compute Cloud (EC2)')->assertSee('Microsoft 365 Copilot')->assertSee('Professional Liability');

        $doc = \App\Models\PrivateDocument::where('kind', SubscriptionChargeImporter::DOC_KIND)->sole();
        $svc = app(ExpenseImportService::class);
        $p = $svc->previewCharges($b, SubscriptionChargeImporter::snapshot($doc));
        $n = count($p['rows']);
        $expected = array_sum(array_map(fn ($s) => count($s->charges($b, Carbon::parse('2026-08-21'), Carbon::parse('2026-11-19'))), app(SubscriptionChargeImporter::class)->sources()));
        $this->assertSame($expected, $n);
        $this->assertTrue($n >= 9 && $n <= 12); // each source bills 3–4 times in a 91-day window
        $cats = $svc->suggestCategories($b, $p['categories']);
        $accts = $svc->suggestAccounts($b, $p['accounts']);
        $acct = fn ($code) => \App\Models\ChartAccount::where('business_id', $b->id)->where('code', $code)->value('id');
        $this->assertSame($acct('6500'), $cats['Cloud Hosting & Software']);
        $this->assertSame($acct('6300'), $cats['Business Insurance']);
        $checking = collect($p['accounts'])->firstWhere(fn ($a) => str_starts_with($a['name'], 'Business Checking'))['name'];
        $this->assertSame($b->account('bank')->id, $accts[$checking]);

        $enc = fn ($m) => collect($m)->mapWithKeys(fn ($v, $k) => [base64_encode($k) => $v])->all();
        $this->signIn($owner)->post($review, ['include' => array_column($p['rows'], 'n'), 'categories' => $enc($cats), 'accounts' => $enc($accts)])->assertRedirect();
        $expenses = Expense::where('business_id', $b->id)->where('source', 'import')->get();
        $this->assertCount($n, $expenses);
        $aws = $expenses->first(fn ($e) => str_starts_with($e->external_id, 'sub:aws:'));
        $this->assertSame('Amazon Web Services', $aws->vendor->name);
        $this->assertCount(7, $aws->lines);
        $this->assertSame($aws->total_cents, (int) $aws->lines->sum('amount_cents'));
        $this->assertSame(SubscriptionChargeImporter::BATCH_KIND, ImportBatch::sole()->kind);

        // Pulling again recognises everything already imported.
        $again = $svc->previewCharges($b, SubscriptionChargeImporter::snapshot(app(SubscriptionChargeImporter::class)->pull($b, ['aws', 'm365', 'hiscox'], Carbon::parse('2026-08-21'))));
        $this->assertSame(array_fill(0, $n, 'imported'), array_column($again['rows'], 'status'));
        $this->signIn($owner)->get($bu.'/expenses/import')->assertOk()->assertSee('last imported');

        $this->signIn($owner)->post($bu.'/expenses/imports/'.ImportBatch::sole()->id.'/undo')->assertRedirect();
        $this->assertSame(0, Expense::where('business_id', $b->id)->whereNull('voided_at')->where('source', 'import')->count());
    }
}
