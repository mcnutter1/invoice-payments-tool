<?php

namespace App\Services\Migration;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use App\Services\Expenses\ExpenseService;
use App\Services\Invoicing\InvoiceService;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CSV migration with fixed templates, dry-run preview, duplicate keys and batch tracking (docs/MIGRATION.md).
 * Opening A/R is posted against Opening Balance Equity (never revenue) so prior-period income is not double counted.
 */
class MigrationService
{
    public const KINDS = [
        'clients' => ['Clients', ['display_name', 'legal_name', 'email', 'phone', 'billing_line1', 'billing_city', 'billing_state', 'billing_postal_code', 'default_terms_days', 'tax_exempt', 'external_id']],
        'catalog' => ['Products & services', ['sku', 'name', 'kind', 'description', 'unit', 'default_price', 'income_account_code']],
        'opening_ar' => ['Opening A/R (unpaid invoices at cutoff)', ['client', 'invoice_number', 'invoice_date', 'due_date', 'open_amount', 'description']],
        'expenses' => ['Historical paid expenses', ['date', 'vendor', 'category_code', 'description', 'amount', 'paid_from_code', 'reference']],
    ];

    public function __construct(private readonly Ledger $ledger, private readonly Context $ctx) {}

    public function template(string $kind): string
    {
        return implode(',', self::KINDS[$kind][1])."\n";
    }

    /** @return list<array{row:int, data:array, key:string, status:string, message:?string}> */
    public function preview(Business $b, string $kind, array $header, array $rows, ?string $cutoff = null): array
    {
        $cols = self::KINDS[$kind][1];
        $idx = array_flip(array_map(fn ($h) => strtolower(trim($h)), $header));
        foreach ($cols as $c) {
            if (! isset($idx[$c])) throw new DomainException("Missing column '{$c}'. Download the template for the exact headers.");
        }
        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $d = [];
            foreach ($cols as $c) $d[$c] = trim((string) ($r[$idx[$c]] ?? ''));
            [$key, $error, $exists] = $this->check($b, $kind, $d, $cutoff);
            $status = $error ? 'error' : ($exists || isset($seen[$key]) ? 'duplicate' : 'new');
            $seen[$key] = true;
            $out[] = ['row' => $i + 2, 'data' => $d, 'key' => $key, 'status' => $status, 'message' => $error ?? ($status === 'duplicate' ? 'Already exists — skipped' : null)];
        }

        return $out;
    }

    private function check(Business $b, string $kind, array $d, ?string $cutoff): array
    {
        try {
            switch ($kind) {
                case 'clients':
                    if ($d['display_name'] === '') return ['', 'display_name is required', false];
                    if ($d['email'] !== '' && ! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return ['', 'invalid email', false];
                    $key = $d['external_id'] !== '' ? 'ext:'.$d['external_id'] : 'name:'.strtolower($d['display_name']);
                    $exists = Client::where('business_id', $b->id)->where(fn ($q) => $d['external_id'] !== ''
                        ? $q->where('external_id', $d['external_id']) : $q->where('display_name', $d['display_name']))->exists();

                    return [$key, null, $exists];
                case 'catalog':
                    if ($d['name'] === '') return ['', 'name is required', false];
                    Money::parse($d['default_price'] ?: '0');
                    if ($d['income_account_code'] !== '' && ! $this->account($b, $d['income_account_code'], 'income')) return ['', 'unknown income_account_code', false];
                    $key = $d['sku'] !== '' ? 'sku:'.$d['sku'] : 'name:'.strtolower($d['name']);
                    $exists = Product::where('business_id', $b->id)->where($d['sku'] !== '' ? 'sku' : 'name', $d['sku'] !== '' ? $d['sku'] : $d['name'])->exists();

                    return [$key, null, $exists];
                case 'opening_ar':
                    if (! $cutoff) return ['', 'choose a cutoff date', false];
                    if ($d['client'] === '' || $d['invoice_number'] === '') return ['', 'client and invoice_number are required', false];
                    $date = Carbon::parse($d['invoice_date']);
                    if ($date->gt(Carbon::parse($cutoff))) return ['', 'invoice_date is after the cutoff — enter it as a normal invoice instead', false];
                    if (Money::parse($d['open_amount']) <= 0) return ['', 'open_amount must be positive', false];
                    if (! Client::where('business_id', $b->id)->where('display_name', $d['client'])->exists()) return ['', 'client not found (import clients first)', false];
                    $key = 'inv:'.$d['invoice_number'];
                    $exists = Invoice::where('business_id', $b->id)->where('number', 'OB-'.$d['invoice_number'])->exists();

                    return [$key, null, $exists];
                case 'expenses':
                    $date = Carbon::parse($d['date']);
                    $amt = Money::parse($d['amount']);
                    if ($amt <= 0) return ['', 'amount must be positive', false];
                    if (! $this->account($b, $d['category_code'], 'expense')) return ['', 'unknown category_code', false];
                    if ($d['paid_from_code'] !== '' && ! $this->account($b, $d['paid_from_code'])) return ['', 'unknown paid_from_code', false];
                    $key = 'exp:'.$date->toDateString().'|'.strtolower($d['vendor']).'|'.$amt.'|'.$d['reference'];
                    $exists = \App\Models\Expense::where('business_id', $b->id)->whereDate('expense_date', $date)->where('total_cents', $amt)
                        ->where('reference', $d['reference'] ?: null)->whereHas('vendor', fn ($q) => $q->where('name', $d['vendor']))->exists();

                    return [$key, null, $exists];
            }
        } catch (\Throwable $e) {
            return ['', $e instanceof DomainException ? $e->getMessage() : 'unreadable value', false];
        }

        return ['', 'unknown import type', false];
    }

    private function account(Business $b, string $code, ?string $type = null): ?ChartAccount
    {
        return ChartAccount::where('business_id', $b->id)->where('code', $code)->when($type, fn ($q) => $q->where('type', $type))->first();
    }

    public function commit(Business $b, string $kind, string $filename, array $preview, ?string $cutoff = null): ImportBatch
    {
        return DB::transaction(function () use ($b, $kind, $filename, $preview, $cutoff) {
            $batch = ImportBatch::create([
                'business_id' => $b->id, 'kind' => 'migration_'.$kind, 'filename' => mb_substr($filename, 0, 200),
                'row_count' => count($preview), 'mapping' => ['cutoff' => $cutoff], 'created_by' => $this->ctx->user?->id,
            ]);
            $done = $dupes = $errors = 0;
            foreach ($preview as $p) {
                if ($p['status'] === 'error') { $errors++; continue; }
                if ($p['status'] === 'duplicate') { $dupes++; continue; }
                $this->createOne($b, $kind, $p['data'], $cutoff, $batch);
                $done++;
            }
            $batch->update(['imported_count' => $done, 'duplicate_count' => $dupes, 'error_count' => $errors]);
            Audit::log($b, 'migration.imported', $batch, null, ['kind' => $kind, 'imported' => $done, 'duplicates' => $dupes, 'errors' => $errors]);

            return $batch;
        });
    }

    private function createOne(Business $b, string $kind, array $d, ?string $cutoff, ImportBatch $batch): void
    {
        switch ($kind) {
            case 'clients':
                $c = new Client([
                    'type' => 'company', 'display_name' => $d['display_name'], 'legal_name' => $d['legal_name'] ?: null, 'email' => $d['email'] ?: null,
                    'phone' => $d['phone'] ?: null, 'billing_line1' => $d['billing_line1'] ?: null, 'billing_city' => $d['billing_city'] ?: null,
                    'billing_state' => $d['billing_state'] ?: null, 'billing_postal_code' => $d['billing_postal_code'] ?: null,
                    'default_terms_days' => $d['default_terms_days'] !== '' ? (int) $d['default_terms_days'] : null,
                    'tax_exempt' => in_array(strtolower($d['tax_exempt']), ['1', 'yes', 'true', 'y'], true), 'external_id' => $d['external_id'] ?: null,
                ]);
                $c->business_id = $b->id;
                $c->save();
                break;
            case 'catalog':
                $p = new Product([
                    'sku' => $d['sku'] ?: null, 'name' => $d['name'], 'kind' => $d['kind'] === 'product' ? 'product' : 'service',
                    'description' => $d['description'] ?: null, 'unit' => $d['unit'] ?: null, 'default_price' => Money::toDecimalString(Money::parse($d['default_price'] ?: '0')),
                    'income_account_id' => ($d['income_account_code'] !== '' ? $this->account($b, $d['income_account_code'], 'income') : null)?->id ?? $b->account('service_income')->id,
                    'active' => true,
                ]);
                $p->business_id = $b->id;
                $p->save();
                break;
            case 'opening_ar':
                $client = Client::where('business_id', $b->id)->where('display_name', $d['client'])->firstOrFail();
                $amt = Money::parse($d['open_amount']);
                $inv = new Invoice([
                    'client_id' => $client->id, 'number' => 'OB-'.$d['invoice_number'], 'status' => 'issued', 'delivery_status' => 'not_sent',
                    'issue_date' => Carbon::parse($d['invoice_date'])->toDateString(), 'due_date' => Carbon::parse($d['due_date'] ?: $d['invoice_date'])->toDateString(),
                    'subtotal_cents' => $amt, 'total_cents' => $amt, 'balance_cents' => $amt, 'currency' => 'USD',
                    'internal_notes' => 'Opening balance imported (batch #'.$batch->id.', cutoff '.$cutoff.')', 'issued_at' => now(),
                    'payment_methods' => $b->allowedMethods(), 'client_snapshot' => $client->snapshot(),
                    'business_snapshot' => \App\Services\Invoicing\InvoicePresenter::businessSnapshot($b),
                    'instructions_snapshot' => \App\Services\Invoicing\InvoicePresenter::instructionsSnapshot($b, $b->allowedMethods()),
                    'template_snapshot' => \App\Services\Invoicing\InvoicePresenter::templateSnapshot($b),
                ]);
                $inv->business_id = $b->id;
                $inv->save();
                $inv->lines()->create(['business_id' => $b->id, 'name' => $d['description'] ?: 'Opening balance', 'quantity' => '1', 'unit_price' => Money::toDecimalString($amt),
                    'income_account_id' => $b->account('service_income')->id, 'tax_rate' => '0', 'amount_cents' => $amt, 'net_cents' => $amt]);
                // Accrual: prior-period revenue was already reported, so credit Opening Balance Equity, not income.
                $entry = $this->ledger->post($b, 'opening_ar:'.$inv->id, $cutoff, 'Opening A/R '.$inv->number, [
                    ['account' => $b->account('ar'), 'debit' => $amt, 'client_id' => $client->id, 'invoice_id' => $inv->id],
                    ['account' => $b->account('opening_balance_equity'), 'credit' => $amt],
                ], ['kind' => 'opening', 'source_type' => 'invoice', 'source_id' => $inv->id, 'allow_closed' => true]);
                $inv->issue_journal_entry_id = $entry->id;
                $inv->save();
                app(InvoiceService::class)->generatePdf($inv, 'opening_import');
                break;
            case 'expenses':
                $vendor = $d['vendor'] !== '' ? Vendor::firstOrCreate(['business_id' => $b->id, 'name' => $d['vendor']]) : null;
                app(ExpenseService::class)->create($b, [
                    'kind' => 'expense', 'vendor_id' => $vendor?->id, 'expense_date' => Carbon::parse($d['date'])->toDateString(),
                    'description' => $d['description'] ?: null, 'reference' => $d['reference'] ?: null,
                    'payment_account_id' => ($d['paid_from_code'] !== '' ? $this->account($b, $d['paid_from_code']) : null)?->id ?? $b->account('bank')->id,
                    'lines' => [['account_id' => $this->account($b, $d['category_code'], 'expense')->id, 'amount_cents' => Money::parse($d['amount']), 'description' => $d['description'] ?: null]],
                ]);
                break;
        }
    }
}
