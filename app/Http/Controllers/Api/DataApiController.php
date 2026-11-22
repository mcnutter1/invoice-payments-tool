<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Services\Reports\ReportService;
use App\Services\Tax\TaxEstimator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only Data API (v1) for downstream systems such as McNutt Legacy. Scoped to the API key's business.
 * Money is returned as decimal strings ("1234.50"); IDs are stable; list endpoints use opaque cursors.
 */
class DataApiController extends Controller
{
    public const VERSION = '1.0';

    private const MAX_LIMIT = 500;

    public const DATASETS = [
        'accounts' => ['ledger.read', 'Chart of accounts'],
        'journal-entries' => ['ledger.read', 'Accrual-basis journal entries with lines (immutable; corrections are reversals)'],
        'cash-events' => ['ledger.read', 'Cash-basis income/expense recognition events'],
        'clients' => ['invoices.read', 'Clients'],
        'invoices' => ['invoices.read', 'Issued and voided invoices with lines and tax'],
        'credit-notes' => ['invoices.read', 'Credit notes and write-offs'],
        'payments' => ['payments.read', 'Payments with fees and allocations'],
        'vendors' => ['expenses.read', 'Vendors'],
        'expenses' => ['expenses.read', 'Expenses and bills with lines'],
        'deletions' => [null, 'Records permanently deleted (purged) since a time'],
        'reports/profit-and-loss' => ['reports.read', 'Profit & loss (cash or accrual)'],
        'reports/balance-sheet' => ['reports.read', 'Balance sheet'],
        'reports/trial-balance' => ['reports.read', 'Trial balance'],
        'reports/tax-estimate' => ['reports.read', 'Owner tax estimate summary'],
    ];

    private static function d(?int $cents): ?string
    {
        if ($cents === null) return null;
        $a = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($a, 100).'.'.str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function ts(?string $v): ?string
    {
        return $v ? Carbon::parse($v)->utc()->toIso8601ZuluString() : null;
    }

    /** Exact decimal from a DECIMAL column without trailing zeros ("600.2500" → "600.25", "2.0000" → "2"). */
    private static function num(mixed $v, int $minDecimals = 0): string
    {
        $s = (string) $v;
        if (str_contains($s, '.')) $s = rtrim(rtrim($s, '0'), '.');
        if ($minDecimals) {
            [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
            $s = $int.'.'.str_pad($frac, $minDecimals, '0');
        }

        return $s;
    }

    private function key(Request $r): ApiKey
    {
        return $r->attributes->get('api_key');
    }

    private function params(Request $r, array $rules): array
    {
        $v = Validator::make($r->query(), $rules + ['limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT], 'cursor' => ['nullable', 'string', 'max:200']]);
        if ($v->fails()) {
            throw new HttpResponseException(AuthenticateApiKey::error('validation_failed', $v->errors()->first(), 422));
        }

        return $v->validated();
    }

    private function cursor(?string $raw): ?array
    {
        if (! $raw) return null;
        $c = json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true);
        if (! is_array($c) || ! isset($c['id']) || ! is_int($c['id'])) {
            throw new HttpResponseException(AuthenticateApiKey::error('invalid_cursor', 'The cursor is invalid. Start again without one.', 400));
        }

        return $c;
    }

    private static function encode(array $c): string
    {
        return rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=');
    }

    private function scoped(string $table): Builder
    {
        return DB::table($table)->where("{$table}.business_id", $this->biz()->id);
    }

    /**
     * Keyset page. Mutable tables order by (updated_at, id) and accept `updated_since`; append-only tables order by id.
     */
    private function page(Builder $q, array $p, \Closure $map, bool $mutable): JsonResponse
    {
        $limit = (int) ($p['limit'] ?? 100);
        $table = $q->from;
        if ($mutable) {
            if (! empty($p['updated_since'])) $q->where("{$table}.updated_at", '>=', Carbon::parse($p['updated_since'])->utc()->toDateTimeString());
            if ($c = $this->cursor($p['cursor'] ?? null)) {
                $q->where(fn ($w) => $w->where("{$table}.updated_at", '>', $c['u'] ?? '')->orWhere(fn ($x) => $x->where("{$table}.updated_at", $c['u'] ?? '')->where("{$table}.id", '>', $c['id'])));
            }
            $q->orderBy("{$table}.updated_at")->orderBy("{$table}.id");
        } else {
            if ($c = $this->cursor($p['cursor'] ?? null)) $q->where("{$table}.id", '>', $c['id']);
            $q->orderBy("{$table}.id");
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();
        $next = $more && $last ? self::encode($mutable ? ['u' => (string) $last->updated_at, 'id' => (int) $last->id] : ['id' => (int) $last->id]) : null;

        return $this->json($map($rows->values()), ['has_more' => $more, 'next_cursor' => $next, 'count' => $rows->count()]);
    }

    private function json(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta + ['business_id' => $this->biz()->ulid, 'api_version' => self::VERSION, 'generated_at' => now()->toIso8601ZuluString()]]);
    }

    private function accountCodes(): array
    {
        return $this->scoped('chart_accounts')->pluck('code', 'id')->all();
    }

    // ------------------------------------------------------------------ discovery

    public function index(Request $r): JsonResponse
    {
        $b = $this->biz();
        $key = $this->key($r);

        return $this->json([
            'business' => [
                'id' => $b->ulid, 'name' => $b->name(), 'legal_name' => $b->legal_name, 'entity_type' => $b->entity_type,
                'reporting_basis' => $b->reporting_basis, 'fiscal_year_start_month' => (int) ($b->fiscal_year_start_month ?: 1),
                'timezone' => $b->timezone, 'currency' => 'USD', 'books_closed_through' => $b->books_closed_through?->toDateString(),
            ],
            'key' => ['name' => $key->name, 'prefix' => $key->prefix, 'scopes' => $key->scopes, 'expires_at' => $key->expires_at?->toIso8601ZuluString()],
            'datasets' => collect(self::DATASETS)->map(fn ($v, $path) => [
                'path' => '/api/v1/data/'.$path, 'scope' => $v[0], 'description' => $v[1], 'available' => ! $v[0] || $key->allows($v[0]),
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------ ledger

    public function accounts(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);

        return $this->page($this->scoped('chart_accounts'), $p, fn ($rows) => $rows->map(fn ($a) => [
            'id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'subtype' => $a->subtype,
            'system_key' => $a->system_key, 'active' => (bool) $a->is_active, 'updated_at' => self::ts($a->updated_at),
        ]), true);
    }

    public function journalEntries(Request $r): JsonResponse
    {
        $p = $this->params($r, ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = $this->scoped('journal_entries');
        if (! empty($p['from'])) $q->where('entry_date', '>=', $p['from']);
        if (! empty($p['to'])) $q->where('entry_date', '<=', $p['to']);
        $codes = $this->accountCodes();

        return $this->page($q, $p, function ($rows) use ($codes) {
            $lines = $this->scoped('journal_lines')->whereIn('journal_entry_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('journal_entry_id');

            return $rows->map(fn ($e) => [
                'id' => (string) $e->id, 'date' => $e->entry_date, 'memo' => $e->memo, 'kind' => $e->kind,
                'source' => ['type' => $e->source_type, 'id' => $e->source_id ? (string) $e->source_id : null, 'key' => $e->source_key],
                'reversal_of_id' => $e->reversal_of_id ? (string) $e->reversal_of_id : null, 'currency' => $e->currency,
                'posted_at' => self::ts($e->created_at),
                'lines' => ($lines[$e->id] ?? collect())->map(fn ($l) => [
                    'id' => (string) $l->id, 'account_id' => (string) $l->account_id, 'account_code' => $codes[$l->account_id] ?? null,
                    'debit' => self::d((int) $l->debit_cents), 'credit' => self::d((int) $l->credit_cents), 'description' => $l->description,
                    'client_id' => $l->client_id ? (string) $l->client_id : null, 'vendor_id' => $l->vendor_id ? (string) $l->vendor_id : null,
                    'invoice_id' => $l->invoice_id ? (string) $l->invoice_id : null,
                ])->values(),
            ]);
        }, false);
    }

    public function cashEvents(Request $r): JsonResponse
    {
        $p = $this->params($r, ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = $this->scoped('cash_basis_projection_events');
        if (! empty($p['from'])) $q->where('event_date', '>=', $p['from']);
        if (! empty($p['to'])) $q->where('event_date', '<=', $p['to']);
        $codes = $this->accountCodes();

        return $this->page($q, $p, fn ($rows) => $rows->map(fn ($c) => [
            'id' => (string) $c->id, 'date' => $c->event_date, 'account_id' => (string) $c->account_id, 'account_code' => $codes[$c->account_id] ?? null,
            'amount' => self::d((int) $c->amount_cents), 'source_key' => $c->source_key, 'description' => $c->description,
            'journal_entry_id' => $c->journal_entry_id ? (string) $c->journal_entry_id : null,
            'invoice_id' => $c->invoice_id ? (string) $c->invoice_id : null, 'expense_id' => $c->expense_id ? (string) $c->expense_id : null,
            'recorded_at' => self::ts($c->created_at),
        ]), false);
    }

    // ------------------------------------------------------------------ invoicing

    public function clients(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);

        return $this->page($this->scoped('clients'), $p, fn ($rows) => $rows->map(fn ($c) => [
            'id' => (string) $c->id, 'type' => $c->type, 'display_name' => $c->display_name, 'legal_name' => $c->legal_name, 'email' => $c->email,
            'billing_address' => array_filter(['line1' => $c->billing_line1, 'line2' => $c->billing_line2, 'city' => $c->billing_city, 'state' => $c->billing_state, 'postal_code' => $c->billing_postal_code]) ?: null,
            'tax_exempt' => (bool) $c->tax_exempt, 'status' => $c->status, 'external_id' => $c->external_id,
            'created_at' => self::ts($c->created_at), 'updated_at' => self::ts($c->updated_at),
        ]), true);
    }

    public function invoices(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date'], 'include_drafts' => ['nullable', 'boolean']]);
        $q = $this->scoped('invoices');
        if (empty($p['include_drafts'])) $q->where('status', '!=', 'draft');

        return $this->page($q, $p, function ($rows) {
            $ids = $rows->pluck('id');
            $lines = $this->scoped('invoice_lines')->whereIn('invoice_id', $ids)->orderBy('position')->orderBy('id')->get()->groupBy('invoice_id');
            $taxes = $this->scoped('invoice_tax_lines')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
            $names = $this->scoped('clients')->whereIn('id', $rows->pluck('client_id'))->pluck('display_name', 'id');

            return $rows->map(fn ($i) => [
                'id' => (string) $i->id, 'ulid' => $i->ulid, 'number' => $i->number, 'status' => $i->status, 'settlement_status' => $i->settlement_status,
                'client_id' => (string) $i->client_id, 'client_name' => $names[$i->client_id] ?? null,
                'issue_date' => $i->issue_date, 'due_date' => $i->due_date, 'service_start' => $i->service_start, 'service_end' => $i->service_end,
                'currency' => $i->currency, 'po_number' => $i->po_number,
                'subtotal' => self::d((int) $i->subtotal_cents), 'discount' => self::d((int) $i->discount_cents), 'tax' => self::d((int) $i->tax_cents),
                'total' => self::d((int) $i->total_cents), 'paid' => self::d((int) $i->paid_cents), 'credited' => self::d((int) $i->credited_cents),
                'written_off' => self::d((int) $i->written_off_cents), 'balance' => self::d($i->status === 'void' ? 0 : (int) $i->balance_cents),
                'recurring_schedule_id' => $i->recurring_schedule_id ? (string) $i->recurring_schedule_id : null,
                'contract_id' => $i->contract_id ? (string) $i->contract_id : null,
                'replaces_invoice_id' => $i->replaces_invoice_id ? (string) $i->replaces_invoice_id : null,
                'issue_journal_entry_id' => $i->issue_journal_entry_id ? (string) $i->issue_journal_entry_id : null,
                'issued_at' => self::ts($i->issued_at), 'voided_at' => self::ts($i->voided_at), 'void_reason' => $i->void_reason,
                'lines' => ($lines[$i->id] ?? collect())->map(fn ($l) => [
                    'id' => (string) $l->id, 'product_id' => $l->product_id ? (string) $l->product_id : null, 'sku' => $l->sku, 'name' => $l->name,
                    'description' => $l->description, 'quantity' => self::num($l->quantity), 'unit_price' => self::num($l->unit_price, 2),
                    'unit' => $l->unit, 'income_account_id' => (string) $l->income_account_id, 'amount' => self::d((int) $l->amount_cents),
                    'discount' => self::d((int) $l->discount_cents), 'net' => self::d((int) $l->net_cents), 'tax' => self::d((int) $l->tax_cents),
                    'tax_name' => $l->tax_name, 'tax_rate' => self::num($l->tax_rate),
                ])->values(),
                'tax_lines' => ($taxes[$i->id] ?? collect())->map(fn ($t) => [
                    'name' => $t->name, 'rate' => self::num($t->rate), 'taxable' => self::d((int) $t->taxable_cents), 'tax' => self::d((int) $t->tax_cents),
                ])->values(),
                'created_at' => self::ts($i->created_at), 'updated_at' => self::ts($i->updated_at),
            ]);
        }, true);
    }

    public function creditNotes(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);

        return $this->page($this->scoped('credit_notes'), $p, function ($rows) {
            $lines = $this->scoped('credit_note_lines')->whereIn('credit_note_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('credit_note_id');

            return $rows->map(fn ($c) => [
                'id' => (string) $c->id, 'number' => $c->number, 'kind' => $c->kind, 'status' => $c->status,
                'client_id' => (string) $c->client_id, 'invoice_id' => $c->invoice_id ? (string) $c->invoice_id : null,
                'issue_date' => $c->issue_date, 'reason' => $c->reason,
                'subtotal' => self::d((int) $c->subtotal_cents), 'tax' => self::d((int) $c->tax_cents), 'total' => self::d((int) $c->total_cents),
                'journal_entry_id' => $c->journal_entry_id ? (string) $c->journal_entry_id : null,
                'lines' => ($lines[$c->id] ?? collect())->map(fn ($l) => [
                    'description' => $l->description, 'income_account_id' => (string) $l->income_account_id,
                    'amount' => self::d((int) $l->amount_cents), 'tax_rate' => self::num($l->tax_rate), 'tax' => self::d((int) $l->tax_cents),
                ])->values(),
                'created_at' => self::ts($c->created_at), 'updated_at' => self::ts($c->updated_at),
            ]);
        }, true);
    }

    // ------------------------------------------------------------------ payments

    public function payments(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);

        return $this->page($this->scoped('payments'), $p, function ($rows) {
            $allocs = $this->scoped('payment_allocations')->whereIn('payment_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('payment_id');

            return $rows->map(fn ($x) => [
                'id' => (string) $x->id, 'ulid' => $x->ulid, 'client_id' => (string) $x->client_id, 'method' => $x->method, 'processor' => $x->processor ?? null,
                'currency' => $x->currency, 'amount' => self::d((int) $x->amount_cents), 'fee' => self::d((int) ($x->fee_cents ?? 0)),
                'net' => self::d((int) $x->amount_cents - (int) ($x->fee_cents ?? 0)), 'received_date' => $x->received_date, 'status' => $x->status,
                'deposit_account_id' => (string) $x->deposit_account_id, 'reference' => $x->reference, 'external_id' => $x->external_id ?? null,
                'deposited_date' => $x->deposited_date, 'cleared_date' => $x->cleared_date,
                'reversed_at' => self::ts($x->reversed_at), 'reversal_kind' => $x->reversal_kind,
                'journal_entry_id' => $x->journal_entry_id ? (string) $x->journal_entry_id : null,
                'deposit_journal_entry_id' => $x->deposit_journal_entry_id ? (string) $x->deposit_journal_entry_id : null,
                'allocations' => ($allocs[$x->id] ?? collect())->map(fn ($a) => [
                    'id' => (string) $a->id, 'invoice_id' => (string) $a->invoice_id, 'amount' => self::d((int) $a->amount_cents),
                    'applied_date' => $a->applied_date, 'status' => $a->status, 'reversed_at' => self::ts($a->reversed_at),
                ])->values(),
                'created_at' => self::ts($x->created_at), 'updated_at' => self::ts($x->updated_at),
            ]);
        }, true);
    }

    // ------------------------------------------------------------------ expenses

    public function vendors(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);

        return $this->page($this->scoped('vendors'), $p, fn ($rows) => $rows->map(fn ($v) => [
            'id' => (string) $v->id, 'name' => $v->name, 'email' => $v->email, 'status' => $v->status,
            'default_account_id' => $v->default_account_id ? (string) $v->default_account_id : null,
            'created_at' => self::ts($v->created_at), 'updated_at' => self::ts($v->updated_at),
        ]), true);
    }

    public function expenses(Request $r): JsonResponse
    {
        $p = $this->params($r, ['updated_since' => ['nullable', 'date']]);
        $codes = $this->accountCodes();

        return $this->page($this->scoped('expenses'), $p, function ($rows) use ($codes) {
            $lines = $this->scoped('expense_lines')->whereIn('expense_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('expense_id');

            return $rows->map(fn ($e) => [
                'id' => (string) $e->id, 'kind' => $e->kind, 'status' => $e->status, 'vendor_id' => $e->vendor_id ? (string) $e->vendor_id : null,
                'reference' => $e->reference, 'expense_date' => $e->expense_date, 'due_date' => $e->due_date, 'description' => $e->description,
                'currency' => $e->currency, 'total' => self::d((int) $e->total_cents), 'paid' => self::d((int) $e->paid_cents),
                'payment_account_id' => $e->payment_account_id ? (string) $e->payment_account_id : null, 'payment_method' => $e->payment_method,
                'client_id' => $e->client_id ? (string) $e->client_id : null, 'project_id' => ($e->project_id ?? null) ? (string) $e->project_id : null,
                'tax_treatment' => $e->tax_treatment, 'source' => $e->source ?? null, 'external_id' => $e->external_id ?? null,
                'journal_entry_id' => $e->journal_entry_id ? (string) $e->journal_entry_id : null, 'voided_at' => self::ts($e->voided_at),
                'lines' => ($lines[$e->id] ?? collect())->map(fn ($l) => [
                    'id' => (string) $l->id, 'account_id' => (string) $l->account_id, 'account_code' => $codes[$l->account_id] ?? null,
                    'description' => $l->description, 'amount' => self::d((int) $l->amount_cents), 'billable' => (bool) $l->billable,
                ])->values(),
                'created_at' => self::ts($e->created_at), 'updated_at' => self::ts($e->updated_at),
            ]);
        }, true);
    }

    // ------------------------------------------------------------------ deletions

    public function deletions(Request $r): JsonResponse
    {
        $p = $this->params($r, ['since' => ['nullable', 'date']]);
        $q = $this->scoped('audit_events')->where('action', 'like', '%.purged');
        if (! empty($p['since'])) $q->where('created_at', '>=', Carbon::parse($p['since'])->utc()->toDateTimeString());

        return $this->page($q, $p, fn ($rows) => $rows->map(fn ($a) => [
            'id' => (string) $a->id, 'object_type' => $a->object_type, 'object_id' => $a->object_id ? (string) $a->object_id : null,
            'action' => $a->action, 'deleted_at' => self::ts($a->created_at),
        ]), false);
    }

    // ------------------------------------------------------------------ reports

    public function profitAndLoss(Request $r, ReportService $reports): JsonResponse
    {
        $b = $this->biz();
        $p = $this->params($r, ['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'basis' => ['nullable', 'in:cash,accrual'], 'monthly' => ['nullable', 'boolean']]);
        $basis = $p['basis'] ?? ($b->reporting_basis ?: 'accrual');
        $d = $reports->profitAndLoss($b, Carbon::parse($p['from'])->toDateString(), Carbon::parse($p['to'])->toDateString(), $basis, ! empty($p['monthly']));
        $acct = fn ($a) => ['account_id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name];

        return $this->json([
            'basis' => $basis,
            'periods' => $d['periods'],
            'sections' => collect($d['sections'])->map(fn ($rows) => collect($rows)->map(fn ($row) => $acct($row['account']) + ['values' => array_map([self::class, 'd'], $row['values'])])->values()),
            'totals' => array_map(fn ($t) => array_map([self::class, 'd'], $t), $d['totals']),
        ]);
    }

    public function balanceSheet(Request $r, ReportService $reports): JsonResponse
    {
        $p = $this->params($r, ['as_of' => ['required', 'date']]);
        $d = $reports->balanceSheet($this->biz(), Carbon::parse($p['as_of'])->toDateString());

        return $this->json([
            'as_of' => Carbon::parse($p['as_of'])->toDateString(), 'fiscal_year_start' => $d['fy_start'],
            'sections' => collect($d['sections'])->map(fn ($rows) => collect($rows)->map(fn ($row) => [
                'account_id' => (string) $row['account']->id, 'code' => $row['account']->code, 'name' => $row['account']->name, 'amount' => self::d($row['amount']),
            ])->values()),
            'retained_earnings' => self::d($d['retained_computed']), 'current_earnings' => self::d($d['current_earnings']),
            'total_assets' => self::d($d['total_assets']), 'total_liabilities' => self::d($d['total_liabilities']),
            'total_equity' => self::d($d['total_equity']), 'balanced' => $d['balanced'],
        ]);
    }

    public function trialBalance(Request $r, ReportService $reports): JsonResponse
    {
        $p = $this->params($r, ['as_of' => ['required', 'date']]);
        $d = $reports->trialBalance($this->biz(), Carbon::parse($p['as_of'])->toDateString());

        return $this->json([
            'as_of' => Carbon::parse($p['as_of'])->toDateString(),
            'rows' => collect($d['rows'])->map(fn ($row) => [
                'account_id' => (string) $row['account']->id, 'code' => $row['account']->code, 'name' => $row['account']->name,
                'debit' => self::d($row['debit']), 'credit' => self::d($row['credit']),
            ])->values(),
            'total_debit' => self::d($d['debit']), 'total_credit' => self::d($d['credit']),
        ]);
    }

    public function taxEstimate(Request $r, TaxEstimator $tax): JsonResponse
    {
        $b = $this->biz();
        $p = $this->params($r, ['year' => ['nullable', 'integer', 'min:2020', 'max:2100']]);
        $e = $tax->estimate($b, (int) ($p['year'] ?? $b->localToday()->year));
        $money = fn (array $a) => array_map(fn ($v) => is_int($v) ? self::d($v) : $v, $a);

        return $this->json([
            'year' => $e['year'], 'as_of' => $e['as_of']->toDateString(), 'projecting' => $e['projecting'],
            'entity' => $e['profile']['entity'], 'form' => $e['form'], 'state' => $e['profile']['state'], 'filing_status' => $e['profile']['filing_status'],
            'net_business_income' => self::d($e['net']),
            'summary' => $money($e['summary']),
            'settlement' => array_map($money, $e['settlement']),
            'warnings' => $e['warnings'],
        ], ['estimate' => true]);
    }
}
