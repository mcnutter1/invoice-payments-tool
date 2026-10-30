<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Every internal page renders for an owner against demo data (catches broken views/queries). */
class PagesSmokeTest extends TestCase
{
    public function test_all_internal_pages_render(): void
    {
        $this->assertSame(0, Artisan::call('finance:demo-seed', ['--force-nonprod' => true]));
        $owner = \App\Models\User::where('central_principal_id', 'demo_owner')->firstOrFail();
        $biz = \App\Models\Business::where('display_name', 'McNutt Consulting')->firstOrFail();
        $bu = '/b/'.$biz->ulid;
        $inv = $biz->invoices()->where('status', 'issued')->first();
        $draft = $biz->invoices()->where('status', 'draft')->first();
        $pay = $biz->payments()->first();
        $contract = $biz->contracts()->first();
        $schedule = $biz->schedules()->first();
        $expense = $biz->expenses()->first();
        $client = $biz->clients()->first();
        $bank = $biz->bankAccounts()->first();
        $entry = $biz->journalEntries()->first();

        $pages = [
            '/businesses', '/businesses/new', $bu, $bu.'/search?q=ac', $bu.'/clients', $bu.'/clients/new', $bu.'/clients/'.$client->id, $bu.'/clients/'.$client->id.'/edit',
            $bu.'/clients/'.$client->id.'/statement', $bu.'/catalog', $bu.'/vendors', $bu.'/invoices', $bu.'/invoices?status=overdue', $bu.'/invoices/new',
            $bu.'/invoices/'.$inv->id, $bu.'/invoices/'.$draft->id.'/edit', $bu.'/invoices/'.$inv->id.'/send', $bu.'/invoices/'.$inv->id.'/credit',
            $bu.'/payments', $bu.'/payments/new?client='.$client->id, $bu.'/payments/'.$pay->id, $bu.'/expenses', $bu.'/expenses/new', $bu.'/expenses/new?kind=bill',
            $bu.'/expenses/'.$expense->id, $bu.'/banking', $bu.'/banking/'.$bank->id, $bu.'/movements/new', $bu.'/accounts', $bu.'/journals', $bu.'/journals/'.$entry->id,
            $bu.'/journals/new', $bu.'/periods', $bu.'/reports', $bu.'/contracts', $bu.'/contracts/new', $bu.'/contracts/'.$contract->id, $bu.'/contract-templates',
            $bu.'/recurring', $bu.'/recurring/new', $bu.'/recurring/'.$schedule->id, $bu.'/recurring/'.$schedule->id.'/edit', $bu.'/settings', $bu.'/settings/members',
            $bu.'/settings/roles', $bu.'/automation', $bu.'/audit', $bu.'/migration', $bu.'/migration/template/clients',
        ];
        foreach (array_keys(\App\Services\Reports\ReportService::REPORTS) as $r) {
            $pages[] = $bu.'/reports/'.$r;
            $pages[] = $bu.'/reports/'.$r.'?format=csv';
        }
        $pages[] = $bu.'/reports/profit-and-loss?basis=cash&monthly=1';
        $pages[] = $bu.'/reports/profit-and-loss?format=pdf';
        foreach ($pages as $p) {
            $res = $this->signIn($owner)->get($p);
            $this->assertSame(200, $res->getStatusCode(), "GET {$p} returned {$res->getStatusCode()}");
            $isPage = str_starts_with($p, $bu.'/') && ! str_contains($p, 'format=') && ! str_contains($p, '/template/');
            if ($isPage) $this->assertStringContainsString('aria-label="Breadcrumb"', $res->getContent(), "No breadcrumb on {$p}");
        }
        $this->signIn($owner)->get($bu)->assertDontSee('aria-label="Breadcrumb"', false);
        $this->signIn($owner)->get($bu.'/invoices/'.$inv->id.'/send')->assertSee('aria-label="Breadcrumb"', false)
            ->assertSee('href="'.$bu.'/invoices"', false)->assertSee('href="'.$bu.'/invoices/'.$inv->id.'"', false)->assertSee('Invoice '.$inv->number);
        $this->signIn($owner)->get($bu.'/clients/'.$client->id.'/edit')->assertSee($client->display_name);
        foreach ([$bu.'/invoices/'.$inv->id.'/pdf', $bu.'/invoices/'.$draft->id.'/pdf', $bu.'/settings/preview-pdf', $bu.'/clients/'.$client->id.'/statement?format=pdf'] as $p) {
            $this->signIn($owner)->get($p)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }
}
