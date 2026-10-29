<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Access\BusinessProvisioner;
use App\Services\Contracts\ContractService;
use App\Services\Contracts\SignatureService;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Payments\PaymentService;
use App\Services\Recurring\RecurringService;
use App\Support\Context;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Non-production demo data: two businesses, shared and restricted members, invoices, contracts, plans. */
class DemoSeed extends Command
{
    protected $signature = 'finance:demo-seed {--force-nonprod : Required confirmation}';

    protected $description = 'Seed demonstration data (refuses to run in production).';

    public function handle(BusinessProvisioner $prov, InvoiceService $inv, PaymentService $pay, ExpenseService $exp, ContractService $con, RecurringService $rec): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }
        if (! $this->option('force-nonprod')) {
            $this->error('Pass --force-nonprod to confirm this is a non-production database.');

            return self::FAILURE;
        }
        $ctx = app(Context::class);
        $owner = User::firstOrCreate(['central_principal_type' => 'user', 'central_principal_id' => 'demo_owner'], ['email' => 'owner@example.test', 'name' => 'Dana Owner']);
        $bookkeeper = User::firstOrCreate(['central_principal_type' => 'user', 'central_principal_id' => 'demo_bookkeeper'], ['email' => 'books@example.test', 'name' => 'Bea Bookkeeper']);
        $ctx->user = $owner;

        $a = $prov->create($owner, ['legal_name' => 'McNutt Consulting LLC', 'display_name' => 'McNutt Consulting', 'timezone' => 'America/New_York',
            'public_email' => 'billing@example.test', 'address_line1' => '10 Harbor Way', 'city' => 'Boston', 'state' => 'MA', 'postal_code' => '02110',
            'zelle_recipient_name' => 'McNutt Consulting LLC', 'zelle_email' => 'pay@example.test', 'check_payee' => 'McNutt Consulting LLC', 'check_address' => "10 Harbor Way\nBoston, MA 02110"]);
        $b = $prov->create($owner, ['legal_name' => 'Seaside Property Services Inc', 'display_name' => 'Seaside Property Services', 'timezone' => 'America/New_York',
            'zelle_recipient_name' => 'Seaside Property Services', 'zelle_phone' => '555-0100', 'check_payee' => 'Seaside Property Services Inc', 'check_address' => "5 Dune Rd\nCape May, NJ 08204"]);
        // Shared bookkeeper only in business A (restricted access to B).
        $m = Membership::create(['business_id' => $a->id, 'user_id' => $bookkeeper->id, 'status' => 'active', 'joined_at' => now()]);
        $m->roles()->attach(Role::where('business_id', $a->id)->where('key', 'accountant')->value('id'), ['business_id' => $a->id]);

        $tax = TaxRate::create(['business_id' => $a->id, 'name' => 'MA Sales Tax', 'rate' => '6.25']);
        $svc = Product::create(['business_id' => $a->id, 'name' => 'Advisory hours', 'kind' => 'service', 'unit' => 'hour', 'default_price' => '175', 'income_account_id' => $a->account('service_income')->id]);
        $lic = Product::create(['business_id' => $a->id, 'name' => 'Software license', 'kind' => 'product', 'default_price' => '400', 'income_account_id' => $a->account('product_income')->id, 'tax_rate_id' => $tax->id]);

        $clients = [];
        foreach ([['Acme Manufacturing', 'ap@acme.example.test'], ['Birch & Co', 'finance@birch.example.test'], ['Cobalt Labs', 'billing@cobalt.example.test']] as [$name, $email]) {
            $c = new Client(['display_name' => $name, 'legal_name' => $name.' Inc.', 'email' => $email, 'billing_line1' => '1 Main St', 'billing_city' => 'Boston', 'billing_state' => 'MA', 'billing_postal_code' => '02110']);
            $c->business_id = $a->id;
            $c->save();
            Contact::create(['business_id' => $a->id, 'client_id' => $c->id, 'name' => 'Pat '.explode(' ', $name)[0], 'email' => $email, 'is_primary_billing' => true, 'is_signer' => true]);
            $clients[] = $c;
        }
        $mk = function (Client $c, array $lines, string $date) use ($inv, $a) {
            $d = $inv->saveDraft($a, null, ['client_id' => $c->id, 'issue_date' => $date, 'lines' => $lines]);

            return $inv->issue($d);
        };
        $today = Carbon::today();
        $paid = $mk($clients[0], [['product_id' => $svc->id, 'quantity' => '8']], $today->copy()->subDays(50)->toDateString());
        $pay->record($a, ['client_id' => $clients[0]->id, 'method' => 'zelle', 'amount_cents' => $paid->total_cents, 'received_date' => $today->copy()->subDays(40)->toDateString()], [$paid->id => $paid->total_cents]);
        $partial = $mk($clients[1], [['product_id' => $svc->id, 'quantity' => '4'], ['product_id' => $lic->id, 'quantity' => '1']], $today->copy()->subDays(20)->toDateString());
        $pay->record($a, ['client_id' => $clients[1]->id, 'method' => 'check', 'amount_cents' => 40000, 'received_date' => $today->copy()->subDays(5)->toDateString(), 'check_number' => '1042'], [$partial->id => 40000]);
        $mk($clients[2], [['product_id' => $svc->id, 'quantity' => '12']], $today->copy()->subDays(75)->toDateString());
        $inv->saveDraft($a, null, ['client_id' => $clients[0]->id, 'lines' => [['name' => 'Workshop facilitation', 'quantity' => '1', 'unit_price' => '2500']]]);
        $exp->create($a, ['kind' => 'expense', 'expense_date' => $today->copy()->subDays(10)->toDateString(), 'description' => 'Cloud hosting', 'payment_account_id' => $a->account('bank')->id,
            'lines' => [['account_id' => \App\Models\ChartAccount::where('business_id', $a->id)->where('code', '6500')->value('id'), 'amount_cents' => 12900]]]);

        // Contracts: one completed via the real signing service, one awaiting signature.
        $done = $con->create($a, ['client_id' => $clients[0]->id, 'title' => 'Advisory retainer', 'template_id' => $a->contractTemplates()->value('id'),
            'effective_date' => $today->copy()->startOfMonth()->toDateString(), 'scope' => 'Monthly strategic advisory.', 'fees' => '$2,000 per month.', 'committed_value' => '24000',
            'signing_order' => 'parallel', 'signers' => [['name' => 'Pat Acme', 'email' => 'ap@acme.example.test']]]);
        $v = $con->prepare($done->currentVersion);
        $con->send($v);
        $r = $v->recipients()->first();
        $r->update(['status' => 'verified', 'verified_at' => now(), 'verified_email' => $r->email]);
        request()->server->set('REMOTE_ADDR', '127.0.0.1');
        app(SignatureService::class)->sign($v->fresh(), $r->fresh(), ['typed_name' => 'Pat Acme', 'consent' => true, 'authority' => true, 'signature_kind' => 'type', 'document_sha256' => $v->sha256]);
        $waiting = $con->create($a, ['client_id' => $clients[1]->id, 'title' => 'Website redesign', 'template_id' => $a->contractTemplates()->value('id'),
            'effective_date' => $today->toDateString(), 'scope' => 'Redesign of marketing site.', 'fees' => 'Fixed fee $9,000 in three milestones.', 'committed_value' => '9000',
            'signing_order' => 'parallel', 'signers' => [['name' => 'Pat Birch', 'email' => 'finance@birch.example.test']],
            'milestones' => [['name' => 'Discovery', 'amount' => '3000'], ['name' => 'Design', 'amount' => '3000'], ['name' => 'Launch', 'amount' => '3000']]]);
        $con->send($con->prepare($waiting->currentVersion));

        $rec->save($a, null, ['client_id' => $clients[0]->id, 'contract_id' => $done->id, 'name' => 'Advisory retainer', 'cadence' => 'month', 'interval' => 1,
            'start_date' => $today->copy()->addDays(3)->toDateString(), 'auto_issue' => true, 'auto_send' => false, 'require_signed_contract' => true,
            'lines' => [['name' => 'Monthly advisory retainer', 'quantity' => '1', 'unit_price' => '2000']]]);

        $c2 = new Client(['display_name' => 'Harbor HOA', 'email' => 'board@harbor.example.test']);
        $c2->business_id = $b->id;
        $c2->save();
        $hoa = $inv->saveDraft($b, null, ['client_id' => $c2->id, 'issue_date' => $today->copy()->subDays(3)->toDateString(), 'lines' => [['name' => 'Grounds maintenance', 'quantity' => '1', 'unit_price' => '850']]]);
        $inv->issue($hoa);

        $this->info("Demo data created. Businesses: {$a->name()} ({$a->ulid}), {$b->name()} ({$b->ulid}).");
        $this->info('Sign in locally with AUTH_DRIVER=dev as uid "demo_owner" (both businesses) or "demo_bookkeeper" (McNutt Consulting only).');

        return self::SUCCESS;
    }
}
