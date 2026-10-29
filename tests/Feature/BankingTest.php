<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Services\Banking\BankingService;
use App\Services\Payments\PaymentService;
use Tests\TestCase;

class BankingTest extends TestCase
{
    public function test_import_dedupe_match_categorize_and_reconcile(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $this->actingSystem($owner);
        $svc = app(BankingService::class);
        $acct = $biz->bankAccounts()->first();
        $client = $this->makeClient($biz);
        app(PaymentService::class)->record($biz, ['client_id' => $client->id, 'method' => 'zelle', 'amount_cents' => 25000, 'received_date' => '2026-06-03']);

        $csv = "Date,Description,Amount\n06/03/2026,ZELLE FROM CLIENT CO,250.00\n06/05/2026,MONTHLY FEE,-15.00\n06/05/2026,MONTHLY FEE,-15.00\n";
        $parsed = $svc->parseCsv($csv);
        $map = ['date' => 0, 'description' => 1, 'amount' => 2, 'debit' => null, 'credit' => null, 'date_format' => 'm/d/Y', 'invert' => false];
        $batch = $svc->import($acct, 'june.csv', $svc->normalize($acct, $parsed['rows'], $map), $map);
        $this->assertSame(3, $batch->imported_count); // two identical fees on the same day are both kept
        $again = $svc->import($acct, 'june.csv', $svc->normalize($acct, $parsed['rows'], $map), $map);
        $this->assertSame(0, $again->imported_count);
        $this->assertSame(3, $again->duplicate_count);
        $linesBefore = JournalLine::count();

        $deposit = BankTransaction::where('amount_cents', 25000)->first();
        $suggestion = $svc->suggestions($deposit)->first();
        $this->assertNotNull($suggestion);
        $svc->match($deposit, $suggestion);
        $this->assertSame($linesBefore, JournalLine::count()); // matching never posts

        $feeAcct = ChartAccount::where('business_id', $biz->id)->where('code', '6100')->first();
        foreach (BankTransaction::where('amount_cents', -1500)->get() as $fee) {
            $svc->categorize($fee, $feeAcct->id);
        }
        $this->assertSame($linesBefore + 4, JournalLine::count());

        $session = $svc->startReconciliation($acct, '2026-06-30', 22000, 0);
        $ids = $svc->reconcilableLines($session)->pluck('id')->all();
        $svc->completeReconciliation($session, $ids);
        $this->assertSame('completed', $session->fresh()->status);

        // Undoing a reconciled row is blocked.
        $this->expectException(\App\Exceptions\DomainException::class);
        $svc->undo($deposit->fresh());
    }

    public function test_reconciliation_difference_blocks_completion(): void
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $this->actingSystem($owner);
        $svc = app(BankingService::class);
        $session = $svc->startReconciliation($biz->bankAccounts()->first(), '2026-06-30', 100, 0);
        $this->expectException(\App\Exceptions\DomainException::class);
        $svc->completeReconciliation($session, []);
    }
}
