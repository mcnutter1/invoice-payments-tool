<?php

namespace App\Services\Access;

use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\ContractTemplate;
use App\Models\InvoiceSequence;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

class BusinessProvisioner
{
    public const DEFAULT_CHART = [
        ['1000', 'Operating Bank Account', 'asset', 'bank', 'bank'],
        ['1100', 'Undeposited Funds', 'asset', 'undeposited', 'undeposited'],
        ['1200', 'Accounts Receivable', 'asset', 'receivable', 'ar'],
        ['1500', 'Equipment', 'asset', 'fixed_asset', null],
        ['2000', 'Accounts Payable', 'liability', 'payable', 'ap'],
        ['2100', 'Sales Tax Payable', 'liability', 'tax', 'sales_tax'],
        ['2200', 'Customer Advances & Credits', 'liability', 'customer_credit', 'customer_advances'],
        ['2300', 'Credit Card Payable', 'liability', 'credit_card', 'credit_card'],
        ['2500', 'Loans Payable', 'liability', 'loan', 'loans'],
        ['3000', 'Owner Contributions', 'equity', 'owner', 'owner_contributions'],
        ['3100', 'Owner Draws', 'equity', 'owner', 'owner_draws'],
        ['3200', 'Retained Earnings', 'equity', 'retained', 'retained_earnings'],
        ['3900', 'Opening Balance Equity', 'equity', 'opening', 'opening_balance_equity'],
        ['4000', 'Service Income', 'income', 'operating', 'service_income'],
        ['4100', 'Product Sales', 'income', 'operating', 'product_income'],
        ['4900', 'Discounts & Refunds Given', 'income', 'contra', 'discounts'],
        ['5000', 'Cost of Goods Sold', 'expense', 'cogs', 'cogs'],
        ['6000', 'Advertising & Marketing', 'expense', 'operating', null],
        ['6100', 'Bank & Merchant Fees', 'expense', 'operating', null],
        ['6150', 'Bad Debt', 'expense', 'operating', 'bad_debt'],
        ['6200', 'Contract Labor', 'expense', 'operating', null],
        ['6300', 'Insurance', 'expense', 'operating', null],
        ['6400', 'Meals', 'expense', 'operating', null],
        ['6500', 'Office Supplies & Software', 'expense', 'operating', null],
        ['6600', 'Professional Fees', 'expense', 'operating', null],
        ['6700', 'Rent & Lease', 'expense', 'operating', null],
        ['6800', 'Travel', 'expense', 'operating', null],
        ['6900', 'Utilities & Phone', 'expense', 'operating', null],
        ['6950', 'Vehicle Expenses', 'expense', 'operating', null],
        ['6990', 'Other Expenses', 'expense', 'operating', null],
        ['7000', 'Interest Expense', 'expense', 'other', null],
    ];

    public static function syncPermissions(): void
    {
        foreach (Permissions::ALL as $key => [$group, $desc]) {
            Permission::updateOrCreate(['key' => $key], ['group' => $group, 'description' => $desc]);
        }
    }

    public function create(User $owner, array $attrs): Business
    {
        return DB::transaction(function () use ($owner, $attrs) {
            self::syncPermissions();
            $business = Business::create(array_merge([
                'display_name' => $attrs['legal_name'] ?? 'New business',
                'payment_methods' => ['zelle', 'check'],
                'sender_name' => $attrs['display_name'] ?? ($attrs['legal_name'] ?? null),
                'backups_enabled' => (bool) app(\App\Services\Backups\BackupSettings::class)->all()['default_enabled'],
            ], $attrs));
            $business->refresh();

            $roles = $this->seedRoles($business);
            $this->seedChart($business);
            $bankChart = $business->account('bank');
            $bank = new \App\Models\BankAccount(['chart_account_id' => $bankChart->id, 'name' => $bankChart->name]);
            $bank->business_id = $business->id;
            $bank->save();
            foreach (['invoice', 'contract', 'credit_note'] as $kind) {
                InvoiceSequence::create(['business_id' => $business->id, 'kind' => $kind, 'next_number' => 1]);
            }
            ContractTemplate::create([
                'business_id' => $business->id,
                'name' => 'Standard service agreement',
                'body' => self::defaultTemplateBody(),
                'created_by' => $owner->id,
            ]);

            $m = Membership::create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $m->roles()->attach($roles['owner']->id, ['business_id' => $business->id]);
            Audit::log($business, 'business.created', $business, null, ['legal_name' => $business->legal_name]);

            return $business;
        });
    }

    /** @return array<string,Role> */
    public function seedRoles(Business $business): array
    {
        $permIds = Permission::pluck('id', 'key');
        $roles = [];
        foreach (Permissions::SYSTEM_ROLES as $key => $name) {
            $role = Role::firstOrCreate(
                ['business_id' => $business->id, 'key' => $key],
                ['name' => $name, 'is_system' => true]
            );
            $role->permissions()->sync(collect(Permissions::defaultsFor($key))->map(fn ($k) => $permIds[$k])->all());
            $roles[$key] = $role;
        }

        return $roles;
    }

    public function seedChart(Business $business): void
    {
        foreach (self::DEFAULT_CHART as [$code, $name, $type, $subtype, $key]) {
            ChartAccount::firstOrCreate(
                ['business_id' => $business->id, 'code' => $code],
                ['name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key]
            );
        }
    }

    public static function defaultTemplateBody(): string
    {
        return <<<'TXT'
# Service Agreement

This Service Agreement ("Agreement") is entered into between **{{business.legal_name}}** ("Provider") and **{{client.legal_name}}** ("Client"), effective {{agreement.effective_date}}.

## 1. Scope of services
{{agreement.scope}}

## 2. Term
This Agreement's term runs {{agreement.term}}, unless terminated earlier under Section 6.

## 3. Fees
{{agreement.fees}}

## 4. Payment terms
Invoices are due within {{agreement.payment_terms_days}} days of the invoice date. Accepted payment methods are described on each invoice.

## 5. Confidentiality
Each party will keep the other party's non-public information confidential and use it only to perform this Agreement.

## 6. Termination
Either party may terminate this Agreement with 30 days' written notice. Fees for services performed through the termination date remain payable.

## 7. General
This Agreement is the entire agreement between the parties regarding its subject matter. Changes must be made by a written amendment signed by both parties.
TXT;
    }
}
