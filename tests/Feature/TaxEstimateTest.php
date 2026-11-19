<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\TaxTable;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Payments\PaymentService;
use App\Services\Tax\TaxEstimator;
use App\Services\Tax\TaxProfile;
use App\Services\Tax\TaxTables;
use App\Services\Tax\TaxTableSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaxEstimateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(array $profile = []): array
    {
        Carbon::setTestNow('2026-11-19 15:00:00');
        $owner = $this->makeUser();
        $b = $this->makeBusiness($owner);
        $b->update(['state' => 'NJ', 'tax_profile' => array_merge(['entity' => 'smllc', 'filing_status' => 'single', 'state' => 'NJ', 'tax_method' => 'cash', 'sstb' => true,
            'include_open_invoices' => true, 'include_recurring' => true], $profile)]);
        $client = $this->makeClient($b);

        return [$owner, $b->fresh(), $client];
    }

    private function paidInvoice($b, $client, string $amount, string $date = '2026-03-02'): void
    {
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($b, null, ['client_id' => $client->id, 'issue_date' => $date, 'lines' => [['name' => 'Consulting', 'quantity' => '1', 'unit_price' => $amount]]]));
        app(PaymentService::class)->record($b, ['client_id' => $client->id, 'method' => 'ach', 'amount_cents' => $inv->total_cents, 'received_date' => $date], [$inv->id => $inv->total_cents]);
    }

    private function expense($b, string $code, int $cents): void
    {
        app(ExpenseService::class)->create($b, ['kind' => 'expense', 'expense_date' => '2026-06-01', 'payment_account_id' => $b->account('bank')->id,
            'lines' => [['account_id' => ChartAccount::where('business_id', $b->id)->where('code', $code)->value('id'), 'amount_cents' => $cents]]]);
    }

    public function test_bracket_math_matches_2026_schedule(): void
    {
        $us = app(TaxTables::class)->resolve('US', 2026)['data'];
        [$tax] = app(TaxEstimator::class)->bracketTax(10000000, $us['brackets']['single']);
        $this->assertSame(1671200, $tax); // 1,240 + 4,560 + 10,912
        [$nj] = app(TaxEstimator::class)->bracketTax(11850000, app(TaxTables::class)->resolve('NJ', 2026)['data']['brackets']['single']);
        $this->assertSame(542220, $nj);
    }

    public function test_single_member_llc_schedule_c_se_qbi_and_nj(): void
    {
        [$owner, $b, $client] = $this->business();
        $this->actingSystem($owner);
        $this->paidInvoice($b, $client, '120000');
        $this->expense($b, '6400', 100000); // meals: 50% added back

        $e = app(TaxEstimator::class)->estimate($b, 2026);
        $this->assertSame('schedule_c', $e['form']);
        $this->assertSame(11950000, $e['net']);
        $se = $e['owner']['se'];
        $this->assertSame((int) round(11950000 * 0.9235), $se['net_earnings']);
        $this->assertSame((int) round($se['net_earnings'] * 0.124), $se['ss']);
        $this->assertSame($se['ss'] + $se['medicare'], $se['total']);
        $this->assertSame(11950000 - $se['half'], $e['owner']['agi']);
        $tiBefore = $e['owner']['agi'] - 1610000;
        $this->assertSame(min((int) round((11950000 - $se['half']) * 0.2), (int) round($tiBefore * 0.2)), $e['owner']['qbi']['deduction']);
        [$expectedTax] = app(TaxEstimator::class)->bracketTax($tiBefore - $e['owner']['qbi']['deduction'], app(TaxTables::class)->resolve('US', 2026)['data']['brackets']['single']);
        $this->assertSame($expectedTax, $e['owner']['income_tax']);
        $this->assertSame($expectedTax + $se['total'], $e['federal_total']);
        // NJ: no deduction for half of SE tax; $1,000 exemption.
        $this->assertSame(542220, $e['state']['total']);
        $this->assertSame($e['federal_total'] + 542220, $e['summary']['total']);
        $this->assertContains('24b Deductible meals', array_column($e['return_lines'], 'line'));
        $this->assertSame(50000, collect($e['return_lines'])->firstWhere('line', '24b Deductible meals')['amount']);
        $this->assertCount(4, $e['estimates']['federal']['installments']);
        $this->assertSame('2027-01-15', $e['estimates']['federal']['installments'][3]['due']->toDateString());
    }

    public function test_balance_due_counts_only_this_business_tax_not_w2_underpayment(): void
    {
        [$owner, $b, $client] = $this->business(['years' => [2026 => ['other_wages' => 8000000, 'fed_withholding' => 100000, 'state_withholding' => 50000,
            'fed_estimates_paid' => 500000, 'state_estimates_paid' => 100000]]]);
        $this->actingSystem($owner);
        $this->paidInvoice($b, $client, '50000');

        $e = app(TaxEstimator::class)->estimate($b, 2026);
        $s = $e['summary'];
        $fedBiz = $e['federal_total'] - $e['without_business']['total'];
        $stateBiz = $e['state']['total'] - $e['state']['without'];
        $this->assertSame($fedBiz + $stateBiz, $s['business_tax']);
        $this->assertSame(600000, $s['paid']);
        $this->assertSame($fedBiz - 500000 + $stateBiz - 100000, $s['balance']);
        // The under-withheld W-2 tax is on the whole return but not in this business's balance.
        $this->assertGreaterThan($s['business_tax'], $s['total']);
    }

    public function test_open_and_recurring_invoices_project_into_the_year_on_cash_method(): void
    {
        [$owner, $b, $client] = $this->business(['doubtful_after_days' => 90]);
        $this->actingSystem($owner);
        $svc = app(InvoiceService::class);
        $svc->issue($svc->saveDraft($b, null, ['client_id' => $client->id, 'issue_date' => '2026-11-01', 'due_date' => '2026-12-01', 'lines' => [['name' => 'Open', 'quantity' => '1', 'unit_price' => '5000']]]));
        $svc->issue($svc->saveDraft($b, null, ['client_id' => $client->id, 'issue_date' => '2026-05-01', 'due_date' => '2026-06-01', 'lines' => [['name' => 'Stale', 'quantity' => '1', 'unit_price' => '700']]]));
        app(\App\Services\Recurring\RecurringService::class)->save($b, null, ['client_id' => $client->id, 'name' => 'Retainer', 'cadence' => 'month', 'interval' => 1,
            'start_date' => '2026-12-01', 'terms_days' => 15, 'lines' => [['name' => 'Retainer', 'quantity' => '1', 'unit_price' => '2000']]]);

        $e = app(TaxEstimator::class)->estimate($b, 2026);
        $this->assertTrue($e['projecting']);
        $this->assertSame(500000, $e['projection']['open_total']);
        $this->assertCount(1, $e['projection']['doubtful']);
        $this->assertSame(200000, $e['projection']['recurring_total']);
        $this->assertSame(700000, $e['net']);

        $b->update(['tax_profile' => array_merge($b->tax_profile, ['include_open_invoices' => false, 'include_recurring' => false])]);
        $this->assertSame(0, app(TaxEstimator::class)->estimate($b->fresh(), 2026)['net']);
    }

    public function test_s_corp_salary_payroll_and_no_se_tax(): void
    {
        [$owner, $b, $client] = $this->business(['entity' => 's_corp', 'years' => [2026 => ['officer_salary' => 6000000]]]);
        $this->actingSystem($owner);
        $this->paidInvoice($b, $client, '150000');

        $e = app(TaxEstimator::class)->estimate($b, 2026);
        $this->assertSame('f1120s', $e['form']);
        $this->assertSame(372000 + 87000 + 4200, $e['payroll']['employer']);
        $this->assertSame(15000000 - 6000000 - 463200, $e['net']);
        $this->assertSame(0, $e['owner']['se']['total']);
        $this->assertSame(463200 + 459000, $e['summary']['payroll']);
        $this->assertSame(6000000, $e['owner']['wages']);
        $this->assertSame(56250, $e['state']['entity_tax']); // NJ S corp minimum tax, $100k–$250k receipts
    }

    public function test_c_corp_flat_rate_and_nj_cbt(): void
    {
        [$owner, $b, $client] = $this->business(['entity' => 'c_corp']);
        $this->actingSystem($owner);
        $this->paidInvoice($b, $client, '200000');

        $e = app(TaxEstimator::class)->estimate($b, 2026);
        $this->assertSame('f1120', $e['form']);
        $this->assertSame((int) round(20000000 * 0.21), $e['federal_total']);
        $this->assertSame((int) round(20000000 * 0.09), $e['state']['total']);
        $this->assertSame('12-15', $e['estimates']['federal']['installments'][3]['due']->format('m-d'));
    }

    public function test_tables_resolve_overrides_and_carry_forward(): void
    {
        $t = app(TaxTables::class);
        $r2027 = $t->resolve('US', 2027);
        $this->assertContains('brackets', $r2027['carried']);
        $t->save('US', 2027, ['ss_wage_base' => 192300], 'ssa.gov');
        $r = app(TaxTables::class)->resolve('US', 2027);
        $this->assertSame(192300, $r['data']['ss_wage_base']);
        $this->assertNotContains('ss_wage_base', $r['carried']);
        $this->assertSame(16100, $r['data']['standard_deduction']['single']);

        $this->expectException(\App\Exceptions\DomainException::class);
        $t->save('NJ', 2027, ['brackets' => ['single' => [[0, 1.4]]]], 'manual');
    }

    public function test_sync_parses_irs_and_ssa_pages(): void
    {
        $rates = '<h2>2027 tax rates for a single taxpayer</h2><table><tr><td>10%</td><td>$0</td><td>$12,700</td></tr><tr><td>12%</td><td>$12,701</td><td>$51,600</td></tr>'
            .'<tr><td>22%</td><td>$51,601</td><td>$108,250</td></tr><tr><td>24%</td><td>$108,251</td><td>$206,650</td></tr><tr><td>32%</td><td>$206,651</td><td>$262,400</td></tr>'
            .'<tr><td>35%</td><td>$262,401</td><td>$656,050</td></tr><tr><td>37%</td><td>$656,051</td><td>And up</td></tr></table>';
        $release = '<p>For tax year 2028, the standard deduction increases to $33,000 for married couples filing jointly. For single taxpayers and married individuals filing separately, the standard deduction rises to $16,500 for tax year 2028, and for heads of households, the standard deduction will be $24,750.</p>'
            .'<p>the top tax rate remains 37% for individual single taxpayers with incomes greater than $660,000 ($792,000 for married couples filing jointly). The other rates are:</p>'
            .'<p>35% for incomes over $264,000 ($528,000 for married couples filing jointly);32% for incomes over $208,000 ($416,000 for married couples filing jointly);24% for incomes over $109,000 ($218,000 for married couples filing jointly);22% for incomes over $52,000 ($104,000 for married couples filing jointly);12% for incomes over $12,800 ($25,600 for married couples filing jointly).</p>';
        Http::fake([
            'www.irs.gov/filing/*' => Http::response($rates.'<p>Find the 2028 <a href="/newsroom/irs-2028">tax rates</a></p>'),
            'www.irs.gov/newsroom/*' => Http::response($release),
            'www.ssa.gov/*' => Http::response('<p>For earnings in 2027, this base is $192,300.</p>'),
        ]);
        $r = app(TaxTableSync::class)->sync();
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        $us27 = app(TaxTables::class)->resolve('US', 2027)['data'];
        $this->assertSame([12700, 0.12], $us27['brackets']['single'][1]);
        $this->assertSame(192300, $us27['ss_wage_base']);
        $us28 = app(TaxTables::class)->resolve('US', 2028)['data'];
        $this->assertSame(16500, $us28['standard_deduction']['single']);
        $this->assertSame([[0, 0.1], [12800, 0.12], [52000, 0.22], [109000, 0.24], [208000, 0.32], [264000, 0.35], [660000, 0.37]], $us28['brackets']['single']);
        $this->assertSame([396000, 0.37], $us28['brackets']['mfs'][6]);
        $this->assertSame(2, TaxTable::count()); // US 2027 (IRS brackets + SSA wage base) and US 2028
        $this->assertSame([], app(TaxTableSync::class)->sync()['changes']); // idempotent
    }

    public function test_report_page_settings_and_exports(): void
    {
        [$owner, $b, $client] = $this->business();
        $this->actingSystem($owner);
        $this->paidInvoice($b, $client, '90000');
        $bu = '/b/'.$b->ulid;
        $this->signIn($owner)->get($bu.'/reports')->assertOk()->assertSee('Tax estimate');
        $this->signIn($owner)->get($bu.'/reports/tax-estimate')->assertOk()->assertSee('Schedule C (Form 1040)')->assertSee('Self-employment tax')->assertSee('New Jersey Gross Income Tax');
        $this->signIn($owner)->post($bu.'/reports/tax-estimate', ['year' => 2026, 'entity' => 'partnership', 'filing_status' => 'mfj', 'state' => 'nj', 'tax_method' => 'cash',
            'ownership_pct' => 50, 'owner_count' => 2, 'dependents' => 1, 'doubtful_after_days' => 90, 'fed_estimates_paid' => '4000', 'partner_se' => 1])->assertRedirect();
        $p = TaxProfile::get($b->fresh(), 2026);
        $this->assertSame(['partnership', 'mfj', 'NJ', 400000], [$p['entity'], $p['filing_status'], $p['state'], $p['fed_estimates_paid']]);
        $this->signIn($owner)->get($bu.'/reports/tax-estimate')->assertOk()->assertSee('Form 1065')->assertSee('Child tax credit');
        $this->signIn($owner)->get($bu.'/reports/tax-estimate?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->signIn($owner)->get($bu.'/reports/tax-estimate?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
