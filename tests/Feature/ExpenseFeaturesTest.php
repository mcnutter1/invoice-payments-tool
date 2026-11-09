<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\MileageRate;
use App\Models\MileageTrip;
use App\Models\Project;
use App\Services\Expenses\ExpenseImportService;
use App\Services\Expenses\ExpenseService;
use App\Services\Expenses\MileageRateSync;
use App\Services\Expenses\MileageService;
use App\Services\Reports\ReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpenseFeaturesTest extends TestCase
{
    private Business $b;
    private $owner;

    private const CSV = <<<'CSV'
Date,Merchant,Category,Account,Original Statement,Notes,Amount,Tags,Owner,Business Entity,Reviewed,Reviewed At,Reviewed By,Pending,Id
2026-06-03,Uber,Taxi & Ride Shares,Citi Prestige Card (...7651),UBER *TRIP HELP.UBER.COM,,-23.45,,Rob,MCNUTT LLC,false,,,false,111
2026-06-03,Uber,Taxi & Ride Shares,Citi Prestige Card (...7651),UBER *TRIP HELP.UBER.COM,,-23.45,,Rob,MCNUTT LLC,false,,,false,112
2026-06-04,Verizon,Phone,CPC CHECKING (...8891),VERIZON WIRELESS PAYMENTS,,-85.10,,Rob,MCNUTT LLC,false,,,false,113
2026-06-05,Carbone,Restaurants & Bars,Platinum Card® (...4002),CARBONE NEW YORK NY,Dinner with Mavericks Group,-212.00,Client,Rob,MCNUTT LLC,false,,,false,114
2026-06-06,Apple,Work Related Technology,American Express Gold Card (...6000),APPLE.COM/BILL,,-9.99,,Rob,MCNUTT LLC,false,,,false,115
2026-06-07,Amazon,Office Supplies & Expenses,Centurion Card® (...1005),AMZN Mktp US,,15.00,,Rob,MCNUTT LLC,false,,,false,116
2026-06-08,Hilton,Travel & Lodging,Platinum Card® (...4002),HILTON NEW YORK,,-410.33,,Rob,MCNUTT LLC,false,,,true,117
CSV;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->owner = $this->makeUser();
        $this->b = $this->makeBusiness($this->owner);
    }

    private function acct(string $code): ChartAccount
    {
        return ChartAccount::where('business_id', $this->b->id)->where('code', $code)->firstOrFail();
    }

    private function assertBalanced(): void
    {
        $this->assertSame((int) JournalLine::where('business_id', $this->b->id)->sum('debit_cents'), (int) JournalLine::where('business_id', $this->b->id)->sum('credit_cents'));
    }

    public function test_monarch_preview_classifies_and_suggests_mappings(): void
    {
        $svc = app(ExpenseImportService::class);
        $p = $svc->preview($this->b, self::CSV);
        $this->assertSame(['new', 'new', 'new', 'new', 'new', 'refund', 'pending'], array_column($p['rows'], 'status'));
        $this->assertSame(2345, $p['rows'][0]['cents']);
        $this->assertNotSame($p['rows'][0]['external_id'], $p['rows'][1]['external_id']);

        $cats = $svc->suggestCategories($this->b, $p['categories']);
        $this->assertSame($this->acct('6800')->id, $cats['Taxi & Ride Shares']);
        $this->assertSame($this->acct('6400')->id, $cats['Restaurants & Bars']);
        $this->assertSame($this->acct('6900')->id, $cats['Phone']);
        $this->assertSame($this->acct('6500')->id, $cats['Work Related Technology']);
        $accts = $svc->suggestAccounts($this->b, $p['accounts']);
        $this->assertSame($this->b->account('bank')->id, $accts['CPC CHECKING (...8891)']);
        $this->assertSame('credit_card', ChartAccount::find($accts['Citi Prestige Card (...7651)'])->subtype);
    }

    public function test_import_flow_dedups_and_marks_imported(): void
    {
        // A hand-entered Verizon bill on a nearby date should be flagged as a match, not duplicated.
        $verizon = new \App\Models\Vendor(['name' => 'Verizon']);
        $verizon->business_id = $this->b->id;
        $verizon->save();
        $manual = app(ExpenseService::class)->create($this->b, ['kind' => 'expense', 'vendor_id' => $verizon->id, 'expense_date' => '2026-06-05',
            'payment_account_id' => $this->b->account('bank')->id, 'lines' => [['account_id' => $this->acct('6900')->id, 'amount_cents' => 8510]]]);

        $bu = '/b/'.$this->b->ulid;
        $res = $this->signIn($this->owner)->post($bu.'/expenses/import', ['csv' => UploadedFile::fake()->createWithContent('Transactions.csv', self::CSV)]);
        $res->assertRedirect();
        $review = $res->headers->get('Location');
        $page = $this->signIn($this->owner)->get($review)->assertOk()->assertSee('Matches existing')->assertSee('Refund / credit')->assertSee('Taxi &amp; Ride Shares', false);

        $svc = app(ExpenseImportService::class);
        $p = $svc->preview($this->b, self::CSV);
        $this->assertSame('match', $p['rows'][2]['status']);
        $this->assertSame($manual->id, $p['rows'][2]['existing_id']);
        $cats = $svc->suggestCategories($this->b, $p['categories']);
        $accts = $svc->suggestAccounts($this->b, $p['accounts']);
        $enc = fn ($m) => collect($m)->mapWithKeys(fn ($v, $k) => [base64_encode($k) => $v])->all();
        $this->signIn($this->owner)->post($review, ['include' => [0, 1, 3, 4], 'categories' => $enc($cats), 'accounts' => $enc($accts), 'remember' => 1])->assertRedirect();

        $imported = Expense::where('business_id', $this->b->id)->where('source', 'import')->orderBy('id')->get();
        $this->assertCount(4, $imported);
        $this->assertSame(['monarch:111', 'monarch:112', 'monarch:114', 'monarch:115'], $imported->pluck('external_id')->all());
        $this->assertFalse((bool) $imported[1]->possible_duplicate);
        $dinner = $imported[2];
        $this->assertSame('Dinner with Mavericks Group', $dinner->description);
        $this->assertSame('CARBONE NEW YORK NY', $dinner->import_meta['statement']);
        $this->assertSame('Carbone', $dinner->vendor->name);
        $this->assertSame($this->acct('6400')->id, $dinner->lines->first()->account_id);
        $this->assertSame($accts['Platinum Card® (...4002)'], $dinner->payment_account_id);
        $this->assertSame(2, \App\Models\Vendor::where('business_id', $this->b->id)->where('name', 'Uber')->count() + \App\Models\Vendor::where('business_id', $this->b->id)->where('name', 'Apple')->count());
        $this->assertNotEmpty($this->b->fresh()->expense_import_mappings['categories']);
        $this->assertBalanced();

        // Re-importing the same file recognises every row.
        $again = $svc->preview($this->b, self::CSV);
        $this->assertSame(['imported', 'imported', 'match', 'imported', 'imported', 'refund', 'pending'], array_column($again['rows'], 'status'));

        $this->signIn($this->owner)->get($bu.'/expenses')->assertOk()->assertSee('Imported');
        $this->signIn($this->owner)->get($bu.'/expenses/'.$dinner->id)->assertOk()->assertSee('Imported transaction')->assertSee('CARBONE NEW YORK NY');
        $this->signIn($this->owner)->get($bu.'/expenses/import')->assertOk()->assertSee('Transactions.csv');

        // Undo voids the batch.
        $batch = $dinner->importBatch;
        $this->signIn($this->owner)->post($bu.'/expenses/imports/'.$batch->id.'/undo')->assertRedirect();
        $this->assertSame(0, Expense::where('import_batch_id', $batch->id)->whereNull('voided_at')->count());
        $this->assertBalanced();
    }

    public function test_editing_an_imported_expense_reposts(): void
    {
        $e = app(ExpenseService::class)->create($this->b, ['kind' => 'expense', 'source' => 'import', 'external_id' => 'monarch:9', 'expense_date' => '2026-06-03',
            'payment_account_id' => $this->b->account('bank')->id, 'description' => 'Uber', 'lines' => [['account_id' => $this->acct('6800')->id, 'amount_cents' => 2345]]]);
        $bu = '/b/'.$this->b->ulid;
        $this->signIn($this->owner)->get($bu.'/expenses/'.$e->id.'/edit')->assertOk()->assertSee('Save changes')->assertSee('23.45');
        $this->signIn($this->owner)->post($bu.'/expenses/'.$e->id, [
            'kind' => 'expense', 'expense_date' => '2026-06-04', 'payment_account_id' => $this->b->account('bank')->id, 'description' => 'Uber to LGA',
            'lines' => [['account_id' => $this->acct('6800')->id, 'amount' => '25.00'], ['account_id' => $this->acct('6400')->id, 'amount' => '4.00']],
        ])->assertRedirect($bu.'/expenses/'.$e->id);

        $e->refresh();
        $this->assertSame(2900, $e->total_cents);
        $this->assertSame(1, $e->revision);
        $this->assertNotNull($e->edited_at);
        $this->assertSame('2026-06-04', $e->expense_date->toDateString());
        $this->assertSame('import', $e->source);
        $travel = (int) JournalLine::where('business_id', $this->b->id)->where('account_id', $this->acct('6800')->id)->sum(\DB::raw('debit_cents - credit_cents'));
        $this->assertSame(2500, $travel);
        $this->assertBalanced();

        // Description-only edits don't repost.
        app(ExpenseService::class)->update($e, ['expense_date' => '2026-06-04', 'payment_account_id' => $e->payment_account_id, 'description' => 'Uber to LGA airport',
            'lines' => [['account_id' => $this->acct('6800')->id, 'amount_cents' => 2500], ['account_id' => $this->acct('6400')->id, 'amount_cents' => 400]]]);
        $this->assertSame(1, $e->fresh()->revision);
    }

    public function test_mileage_uses_irs_rate_for_date_and_reprices(): void
    {
        $svc = app(MileageService::class);
        $q = $svc->quote('2025-03-01', 10.5, false);
        $this->assertSame(735, $q['cents']);
        $this->assertFalse($q['provisional']);
        $this->assertSame(1470, $svc->quote('2025-03-01', 10.5, true)['cents']);
        $this->assertSame(1313, $svc->quote('2022-08-01', 10.5, true)['cents']); // 21 mi × 62.5¢
        $this->assertTrue($svc->quote('2026-06-01', 10, false)['provisional']);

        $c = $this->makeClient($this->b, 'Mavericks Group');
        $bu = '/b/'.$this->b->ulid;
        $this->signIn($this->owner)->get($bu.'/mileage/new')->assertOk()->assertSee('data-mileage-rates', false);
        $this->signIn($this->owner)->post($bu.'/mileage', ['trip_date' => '2026-06-10', 'purpose' => 'Client meeting', 'from_location' => 'Home', 'to_location' => 'Mavericks HQ',
            'miles' => '12.3', 'round_trip' => '1', 'client_id' => $c->id, 'payment_account_id' => $this->b->account('owner_contributions')->id, 'billable' => '1'])->assertRedirect();
        $trip = MileageTrip::firstOrFail();
        $this->assertSame(1722, $trip->amount_cents); // 24.6 mi × 70¢ (2025 rate, provisional)
        $this->assertSame('mileage', $trip->expense->source);
        $this->assertSame($c->id, $trip->expense->client_id);
        $this->assertTrue((bool) $trip->expense->lines->first()->billable);
        $this->signIn($this->owner)->get($bu.'/mileage')->assertOk()->assertSee('Mavericks HQ')->assertSee('hasn’t published', false);

        MileageRate::create(['effective_from' => '2026-01-01', 'business_rate_millis' => 725, 'source' => 'irs.gov']);
        $this->signIn($this->owner)->post($bu.'/mileage/reprice')->assertRedirect();
        $trip->refresh();
        $this->assertSame(725, $trip->rate_millis);
        $this->assertSame(1784, $trip->amount_cents); // 246 × 725 / 100 = 1783.5 → 1784
        $this->assertSame(1784, $trip->expense->fresh()->total_cents);
        $this->assertTrue((bool) $trip->expense->fresh()->lines->first()->billable);
        $this->assertBalanced();

        $this->signIn($this->owner)->post($bu.'/mileage/'.$trip->id.'/delete')->assertRedirect();
        $this->assertNotNull(Expense::find($trip->expense_id)->voided_at);
        $this->assertBalanced();
    }

    public function test_irs_rate_sync_parses_table(): void
    {
        $html = '<table><thead><tr><th>Period</th><th>Business</th><th>Medical/Moving</th><th>Charitable</th></tr></thead><tbody>'
            .'<tr><td>2026</td><td>72.5</td><td>20.5</td><td>14</td></tr>'
            .'<tr><td>2025</td><td>70</td><td>21</td><td>14</td></tr>'
            .'<tr><td>July 1 - Dec. 31, 2022</td><td>62.5</td><td>22</td><td>14</td></tr></tbody></table>';
        $sync = app(MileageRateSync::class);
        $this->assertSame([['2026-01-01', 725], ['2025-01-01', 700], ['2022-07-01', 625]], $sync->parse($html));
        Http::fake([MileageRateSync::URL => Http::response($html)]);
        $r = $sync->sync();
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['added']);
        $this->assertSame(725, MileageRate::forDate('2026-03-01')->business_rate_millis);
        $this->assertSame(0, count($sync->sync()['added']));
    }

    public function test_projects_group_expenses_and_report_by_client(): void
    {
        $c = $this->makeClient($this->b, 'Mavericks Group');
        $other = $this->makeClient($this->b, 'Other Co');
        $bu = '/b/'.$this->b->ulid;
        $this->signIn($this->owner)->post($bu.'/projects', ['name' => 'NYC meeting with Mavericks Group', 'client_id' => $c->id, 'start_date' => '2026-06-03', 'end_date' => '2026-06-05'])->assertRedirect();
        $p = Project::firstOrFail();
        $svc = app(ExpenseService::class);
        $mk = fn (array $extra, int $cents, string $code) => $svc->create($this->b, $extra + ['kind' => 'expense', 'expense_date' => '2026-06-04',
            'payment_account_id' => $this->b->account('bank')->id, 'lines' => [['account_id' => $this->acct($code)->id, 'amount_cents' => $cents]]]);
        $hotel = $mk(['project_id' => $p->id], 41033, '6800');
        $this->assertSame($c->id, $hotel->client_id); // inherited from the project
        $mk(['project_id' => $p->id, 'description' => 'Dinner'], 21200, '6400');
        $mk([], 8510, '6900'); // run-of-business
        $this->expectExceptionOnce(fn () => $mk(['project_id' => $p->id, 'client_id' => $other->id], 100, '6990'));

        $r = app(ReportService::class)->expensesByAssignment($this->b, '2026-06-01', '2026-06-30', 'client');
        $this->assertSame([['Mavericks Group', 62233], ['General business (no client)', 8510]], array_map(fn ($x) => [$x['label'], $x['amount']], $r['rows']));
        $rp = app(ReportService::class)->expensesByAssignment($this->b, '2026-06-01', '2026-06-30', 'project');
        $this->assertSame('NYC meeting with Mavericks Group · Mavericks Group', $rp['rows'][0]['label']);

        $this->signIn($this->owner)->get($bu.'/projects')->assertOk()->assertSee('NYC meeting with Mavericks Group')->assertSee('$622.33');
        $this->signIn($this->owner)->get($bu.'/projects/'.$p->id)->assertOk()->assertSee('Dinner')->assertSee('Meals');
        $this->signIn($this->owner)->get($bu.'/projects/'.$p->id.'/edit')->assertOk();
        $this->signIn($this->owner)->get($bu.'/clients/'.$c->id)->assertOk()->assertSee('NYC meeting with Mavericks Group')->assertSee('$622.33');
        $this->signIn($this->owner)->get($bu.'/expenses?project='.$p->id)->assertOk()->assertSee('Dinner');
        $this->signIn($this->owner)->get($bu.'/reports/expenses-by-client')->assertOk()->assertSee('General business');
        $this->signIn($this->owner)->get($bu.'/reports/expenses-by-project?format=csv')->assertOk();
    }

    private function expectExceptionOnce(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('different client', $e->getMessage());
        }
    }

    public function test_platform_mileage_rates_page(): void
    {
        $admin = $this->makeUser('u_root', null, ['admin', 'user']);
        $this->signIn($admin)->get('/platform/mileage-rates')->assertOk()->assertSee('70¢ / mile');
        $this->signIn($admin)->post('/platform/mileage-rates', ['effective_from' => '2026-01-01', 'cents' => '72.5'])->assertRedirect();
        $this->assertSame(725, MileageRate::forDate('2026-02-01')->business_rate_millis);
        $this->signIn($this->owner)->get('/platform/mileage-rates')->assertForbidden();
    }
}
