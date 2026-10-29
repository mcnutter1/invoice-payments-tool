<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\Migration\MigrationService;
use App\Services\Payments\PaymentService;
use App\Services\Reports\ReportService;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_dry_run_dedupe_and_opening_ar_does_not_double_count_revenue(): void
    {
        $owner = $this->makeUser();
        $b = $this->makeBusiness($owner);
        $this->actingSystem($owner);
        $svc = app(MigrationService::class);

        $hdr = MigrationService::KINDS['clients'][1];
        $rows = [['Acme', 'Acme Inc', 'ap@acme.test', '', '', '', '', '', '30', 'no', 'QB-1'], ['Acme', 'Acme Inc', 'ap@acme.test', '', '', '', '', '', '30', 'no', 'QB-1'], ['', '', 'bad', '', '', '', '', '', '', '', '']];
        $preview = $svc->preview($b, 'clients', $hdr, $rows);
        $this->assertSame(['new', 'duplicate', 'error'], array_column($preview, 'status'));
        $this->assertSame(0, Client::count()); // dry run
        $svc->commit($b, 'clients', 'c.csv', $preview);
        $this->assertSame(1, Client::count());
        $this->assertSame('duplicate', $svc->preview($b, 'clients', $hdr, [$rows[0]])[0]['status']);

        $ar = $svc->preview($b, 'opening_ar', MigrationService::KINDS['opening_ar'][1], [['Acme', '1001', '2025-12-15', '2026-01-14', '1,200.00', 'December services']], '2025-12-31');
        $this->assertSame('new', $ar[0]['status']);
        $svc->commit($b, 'opening_ar', 'ar.csv', $ar, '2025-12-31');
        $inv = Invoice::where('number', 'OB-1001')->firstOrFail();
        $this->assertSame(120000, $inv->balance_cents);

        $r = app(ReportService::class);
        $this->assertSame(0, $r->profitAndLoss($b, '2025-01-01', '2026-12-31', 'accrual')['totals'][0]['income']);
        app(PaymentService::class)->record($b, ['client_id' => $inv->client_id, 'method' => 'check', 'amount_cents' => 120000, 'received_date' => '2026-01-10'], [$inv->id => 120000]);
        $this->assertSame(0, $r->profitAndLoss($b, '2026-01-01', '2026-12-31', 'accrual')['totals'][0]['income']);
        $this->assertSame(120000, $r->profitAndLoss($b, '2026-01-01', '2026-12-31', 'cash')['totals'][0]['income']);
        $this->assertTrue($r->balanceSheet($b, '2026-12-31')['balanced']);
    }
}
