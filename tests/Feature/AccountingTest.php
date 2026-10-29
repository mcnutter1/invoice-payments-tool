<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentClaim;
use App\Services\Banking\BankingService;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Payments\PaymentService;
use App\Services\Reports\ReportService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    private Business $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $owner = $this->makeUser();
        $this->b = $this->makeBusiness($owner);
    }

    private function issue(int $client, array $lines, ?string $date = '2026-06-01')
    {
        $svc = app(InvoiceService::class);

        return $svc->issue($svc->saveDraft($this->b, null, ['client_id' => $client, 'issue_date' => $date, 'lines' => $lines]));
    }

    private function assertLedgerBalanced(): void
    {
        $this->assertSame(
            (int) JournalLine::where('business_id', $this->b->id)->sum('debit_cents'),
            (int) JournalLine::where('business_id', $this->b->id)->sum('credit_cents')
        );
        $tb = app(ReportService::class)->trialBalance($this->b, '2026-12-31');
        $this->assertSame($tb['debit'], $tb['credit']);
        $this->assertTrue(app(ReportService::class)->balanceSheet($this->b, '2026-12-31')['balanced']);
    }

    public function test_issue_posts_once_and_partial_payment_leaves_balance(): void
    {
        $c = $this->makeClient($this->b);
        $inv = $this->issue($c->id, [['name' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000']]);
        $this->assertSame('INV-000001', $inv->number);
        // Issuing twice is idempotent.
        app(InvoiceService::class)->issue($inv);
        $this->assertSame(1, JournalEntry::where('business_id', $this->b->id)->where('source_type', 'invoice')->count());

        $claim = new PaymentClaim(['invoice_id' => $inv->id, 'method' => 'zelle', 'reported_amount_cents' => 40000, 'reported_date' => '2026-06-10']);
        $claim->business_id = $this->b->id;
        $claim->save();
        // A claim alone changes nothing financially.
        $this->assertSame(100000, $inv->fresh()->balance_cents);
        $this->assertSame(0, (int) JournalLine::where('business_id', $this->b->id)->where('account_id', $this->b->account('bank')->id)->sum('debit_cents'));

        $p1 = app(PaymentService::class)->confirmClaim($claim, ['amount_cents' => 40000, 'received_date' => '2026-06-10']);
        $p2 = app(PaymentService::class)->confirmClaim($claim->fresh(), ['amount_cents' => 40000, 'received_date' => '2026-06-10']);
        $this->assertSame($p1->id, $p2->id);
        $this->assertSame(60000, $inv->fresh()->balance_cents);
        $this->assertSame('partial', $inv->fresh()->settlement_status);
        $this->assertSame(1, JournalEntry::where('business_id', $this->b->id)->where('source_type', 'payment')->count());
        $this->assertLedgerBalanced();
    }

    public function test_cash_vs_accrual_golden_scenario(): void
    {
        $this->b->taxRates()->create(['name' => 'Tax 10', 'rate' => '10']);
        $tax = $this->b->taxRates()->first();
        $c = $this->makeClient($this->b);
        $inv = $this->issue($c->id, [['name' => 'Work', 'quantity' => '1', 'unit_price' => '1000', 'tax_rate_id' => $tax->id]]);
        $this->assertSame(110000, $inv->total_cents);
        app(PaymentService::class)->record($this->b, ['client_id' => $c->id, 'method' => 'zelle', 'amount_cents' => 55000, 'received_date' => '2026-06-05'], [$inv->id => 55000]);
        $expenseAcct = ChartAccount::where('business_id', $this->b->id)->where('code', '6500')->first();
        app(ExpenseService::class)->create($this->b, ['kind' => 'expense', 'expense_date' => '2026-06-06', 'payment_account_id' => $this->b->account('bank')->id,
            'lines' => [['account_id' => $expenseAcct->id, 'amount_cents' => 20000]]]);

        $r = app(ReportService::class);
        $cash = $r->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'cash')['totals'][0];
        $accrual = $r->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'accrual')['totals'][0];
        $this->assertSame(50000, $cash['income']);
        $this->assertSame(30000, $cash['net']);
        $this->assertSame(100000, $accrual['income']);
        $this->assertSame(80000, $accrual['net']);
        $taxCash = (int) \App\Models\CashProjectionEvent::where('business_id', $this->b->id)->where('account_id', $this->b->account('sales_tax')->id)->sum('amount_cents');
        $this->assertSame(5000, $taxCash);
        $this->assertLedgerBalanced();
    }

    public function test_cumulative_cash_recognition_converges_exactly(): void
    {
        $c = $this->makeClient($this->b);
        $inv = $this->issue($c->id, [['name' => 'A', 'quantity' => '1', 'unit_price' => '333.33'], ['name' => 'B', 'quantity' => '1', 'unit_price' => '666.67']]);
        $pay = app(PaymentService::class);
        foreach ([33333, 33333, 33334] as $amt) {
            $pay->record($this->b, ['client_id' => $c->id, 'method' => 'zelle', 'amount_cents' => $amt, 'received_date' => '2026-06-10'], [$inv->id => $amt]);
        }
        $cash = app(ReportService::class)->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'cash')['totals'][0];
        $this->assertSame(100000, $cash['income']);
        $this->assertSame('paid', $inv->fresh()->settlement_status);
    }

    public function test_non_pnl_activity_and_deposits_do_not_create_revenue(): void
    {
        $bank = $this->b->account('bank');
        $svc = app(BankingService::class);
        $savings = $svc->createAccount($this->b, ['name' => 'Savings', 'type' => 'bank']);
        foreach ([['owner_contribution', null, $bank->id], ['owner_draw', $bank->id, null], ['loan_proceeds', null, $bank->id], ['loan_payment', $bank->id, null], ['transfer', $bank->id, $savings->chart_account_id]] as [$k, $from, $to]) {
            $svc->recordMovement($this->b, ['kind' => $k, 'movement_date' => '2026-06-02', 'amount_cents' => 10000, 'from_account_id' => $from, 'to_account_id' => $to]);
        }
        // Customer deposit with no invoice yet -> liability, not income.
        $c = $this->makeClient($this->b);
        $pay = app(PaymentService::class)->record($this->b, ['client_id' => $c->id, 'method' => 'zelle', 'amount_cents' => 50000, 'received_date' => '2026-06-03']);
        $r = app(ReportService::class);
        foreach (['cash', 'accrual'] as $basis) {
            $this->assertSame(0, $r->profitAndLoss($this->b, '2026-06-01', '2026-06-30', $basis)['totals'][0]['net']);
        }
        // Apply the deposit to a later invoice: income recognized once.
        $inv = $this->issue($c->id, [['name' => 'Work', 'quantity' => '1', 'unit_price' => '500']], '2026-06-20');
        app(PaymentService::class)->apply($pay, $inv, 50000, '2026-06-20');
        $this->assertSame(50000, $r->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'accrual')['totals'][0]['income']);
        $this->assertSame(50000, $r->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'cash')['totals'][0]['income']);
        $this->assertLedgerBalanced();
    }

    public function test_overpayment_refund_returned_check_and_credit_note(): void
    {
        $c = $this->makeClient($this->b);
        $inv = $this->issue($c->id, [['name' => 'Work', 'quantity' => '1', 'unit_price' => '300']]);
        $svc = app(PaymentService::class);
        $p = $svc->record($this->b, ['client_id' => $c->id, 'method' => 'check', 'amount_cents' => 35000, 'received_date' => '2026-06-05', 'check_number' => '101'], [$inv->id => 30000]);
        $this->assertSame(5000, $p->unappliedCents());
        $this->assertSame($this->b->account('undeposited')->id, $p->deposit_account_id);
        $svc->deposit($p, $this->b->account('bank'), '2026-06-06');
        $svc->refund($p->fresh(), 5000, $this->b->account('bank')->id, '2026-06-07');
        $this->assertSame(0, $p->fresh()->unappliedCents());

        // Bounced check on a second invoice reopens it and removes cash income.
        $inv2 = $this->issue($c->id, [['name' => 'More', 'quantity' => '1', 'unit_price' => '200']]);
        $p2 = $svc->record($this->b, ['client_id' => $c->id, 'method' => 'check', 'amount_cents' => 20000, 'received_date' => '2026-06-08'], [$inv2->id => 20000]);
        $this->assertSame(0, $inv2->fresh()->balance_cents);
        $svc->reverse($p2, 'returned', 'NSF', '2026-06-12');
        $this->assertSame(20000, $inv2->fresh()->balance_cents);
        $cash = app(ReportService::class)->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'cash')['totals'][0];
        $this->assertSame(30000, $cash['income']);

        // Credit note reduces revenue and balance.
        $cn = app(InvoiceService::class)->credit($inv2->fresh(), [['description' => 'Discount', 'amount_cents' => 5000]], 'Goodwill');
        $this->assertSame(15000, $inv2->fresh()->balance_cents);
        $accrual = app(ReportService::class)->profitAndLoss($this->b, '2026-06-01', '2026-06-30', 'accrual')['totals'][0];
        $this->assertSame(45000, $accrual['income']);
        $this->assertSame('CN-000001', $cn->number);
        $this->assertLedgerBalanced();
    }

    public function test_void_reverses_and_closed_period_rejects_backdating(): void
    {
        $c = $this->makeClient($this->b);
        $inv = $this->issue($c->id, [['name' => 'Work', 'quantity' => '1', 'unit_price' => '100']]);
        app(InvoiceService::class)->void($inv, 'Wrong client');
        $this->assertSame(0, app(ReportService::class)->profitAndLoss($this->b, '2026-01-01', '2026-12-31', 'accrual')['totals'][0]['income']);

        $this->b->update(['books_closed_through' => '2026-05-31']);
        $this->expectException(\App\Services\Accounting\ClosedPeriodException::class);
        $this->issue($c->id, [['name' => 'Late', 'quantity' => '1', 'unit_price' => '100']], '2026-05-15');
    }

    public function test_ar_aging_buckets_as_of(): void
    {
        $c = $this->makeClient($this->b);
        $this->issue($c->id, [['name' => 'Old', 'quantity' => '1', 'unit_price' => '100']], '2026-03-01'); // due 03-31
        $this->issue($c->id, [['name' => 'New', 'quantity' => '1', 'unit_price' => '50']], '2026-06-10');
        $aging = app(ReportService::class)->arAging($this->b, '2026-06-15');
        $this->assertSame(5000, $aging['buckets']['current']);
        $this->assertSame(10000, $aging['buckets']['61-90']);
        $this->assertSame(15000, $aging['total']);
    }
}
