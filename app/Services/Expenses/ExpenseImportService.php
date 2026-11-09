<?php

namespace App\Services\Expenses;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\ImportBatch;
use App\Models\Vendor;
use App\Services\Audit;
use App\Services\Banking\BankingService;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Imports expenses from a Monarch Money (or similar) transactions CSV, with de-duplication and remembered category mapping. */
class ExpenseImportService
{
    /** Recognised headers (lower-case) → field. */
    private const COLUMNS = [
        'date' => 'date', 'merchant' => 'merchant', 'category' => 'category', 'account' => 'account',
        'original statement' => 'statement', 'notes' => 'notes', 'amount' => 'amount', 'tags' => 'tags',
        'business entity' => 'entity', 'pending' => 'pending', 'id' => 'id', 'description' => 'statement',
    ];

    /** Keyword → chart account name pattern, for first-time category suggestions. */
    private const CATEGORY_HINTS = [
        '/travel|lodging|hotel|air|flight|ground transport|taxi|ride|uber|lyft|parking|toll|rental car|train/i' => '/travel/i',
        '/restaurant|bar|meal|food|coffee|dining|groceries/i' => '/meal/i',
        '/phone|internet|utilit|electric|water|cable/i' => '/utilit|phone/i',
        '/software|technology|subscription|computer|office|supplies|electronics/i' => '/office|software/i',
        '/advertis|marketing/i' => '/advertis|marketing/i',
        '/insurance/i' => '/insurance/i',
        '/legal|accounting|professional|consult/i' => '/professional/i',
        '/rent|lease/i' => '/rent|lease/i',
        '/fee|bank|interest charge/i' => '/fee/i',
        '/contract|freelanc/i' => '/contract labor/i',
        '/auto|vehicle|fuel|gas station|car /i' => '/vehicle/i',
    ];

    public function __construct(private readonly BankingService $banking, private readonly ExpenseService $expenses, private readonly Context $ctx) {}

    /**
     * Parse and classify every row. Statuses: new, imported (same source id already imported), match (looks like an existing
     * expense), refund (money in), pending, error.
     */
    public function preview(Business $b, string $csv): array
    {
        ['header' => $header, 'rows' => $raw] = $this->banking->parseCsv($csv);
        $idx = [];
        foreach ($header as $i => $h) {
            $f = self::COLUMNS[strtolower(trim($h))] ?? null;
            if ($f && ! isset($idx[$f])) $idx[$f] = $i;
        }
        if (! isset($idx['date'], $idx['amount']) || (! isset($idx['merchant']) && ! isset($idx['statement']))) {
            throw new DomainException('This doesn’t look like a transactions export: it needs Date, Amount and Merchant (or Original Statement) columns.');
        }
        $get = fn (array $r, string $f) => isset($idx[$f]) ? trim((string) ($r[$idx[$f]] ?? '')) : '';
        $seen = [];
        $rows = [];
        foreach ($raw as $n => $r) {
            $row = ['n' => $n, 'merchant' => $get($r, 'merchant') ?: Str::limit($get($r, 'statement'), 60, ''), 'category' => $get($r, 'category') ?: 'Uncategorized',
                'account' => $get($r, 'account') ?: 'Unknown account', 'statement' => $get($r, 'statement'), 'notes' => $get($r, 'notes'),
                'tags' => $get($r, 'tags'), 'entity' => $get($r, 'entity'), 'status' => 'new', 'error' => null, 'existing_id' => null];
            try {
                $row['date'] = Carbon::parse($get($r, 'date'))->toDateString();
                $cents = Money::parse(str_replace(['$', ','], '', $get($r, 'amount')));
            } catch (\Throwable) {
                $rows[] = $row + ['date' => null, 'cents' => 0, 'status' => 'error', 'error' => 'Unreadable date or amount', 'external_id' => null];
                continue;
            }
            $row['cents'] = abs($cents);
            $fp = $row['date'].'|'.$cents.'|'.strtolower($row['statement'] ?: $row['merchant']);
            $seen[$fp] = ($seen[$fp] ?? 0) + 1;
            $row['external_id'] = $get($r, 'id') !== '' ? 'monarch:'.$get($r, 'id') : 'csv:'.substr(hash('sha256', $fp.'|'.$seen[$fp]), 0, 40);
            if ($cents >= 0) $row['status'] = 'refund';
            elseif (strtolower($get($r, 'pending')) === 'true') $row['status'] = 'pending';
            $rows[] = $row;
        }

        // Already imported, or matching something entered by hand / from another source.
        $ext = Expense::where('business_id', $b->id)->whereIn('external_id', array_filter(array_column($rows, 'external_id')))->whereNull('voided_at')->pluck('id', 'external_id');
        $dates = array_filter(array_column($rows, 'date'));
        $existing = $dates ? Expense::with('vendor')->where('business_id', $b->id)->whereNull('voided_at')
            ->whereBetween('expense_date', [Carbon::parse(min($dates))->subDays(3)->toDateString(), Carbon::parse(max($dates))->addDays(3)->toDateString()])
            ->get(['id', 'vendor_id', 'expense_date', 'total_cents', 'external_id', 'description']) : collect();
        $claimed = [];
        foreach ($rows as &$row) {
            if ($row['status'] !== 'new' && $row['status'] !== 'pending') continue;
            if (isset($ext[$row['external_id']])) {
                $row['status'] = 'imported';
                $row['existing_id'] = $ext[$row['external_id']];
                continue;
            }
            $m = $existing->first(fn ($e) => ! isset($claimed[$e->id]) && $e->total_cents === $row['cents'] && ! $e->external_id
                && abs($e->expense_date->diffInDays(Carbon::parse($row['date']))) <= 3 && $this->sameVendor($e, $row['merchant']));
            if ($m) {
                $row['status'] = 'match';
                $row['existing_id'] = $m->id;
                $claimed[$m->id] = true;
            }
        }
        unset($row);

        $summary = fn (string $key) => collect($rows)->whereIn('status', ['new', 'match', 'pending'])->groupBy($key)
            ->map(fn ($g, $k) => ['name' => $k, 'count' => $g->count(), 'cents' => $g->sum('cents')])->sortByDesc('cents')->values()->all();

        return ['rows' => $rows, 'categories' => $summary('category'), 'accounts' => $summary('account'),
            'entities' => collect($rows)->pluck('entity')->filter()->unique()->values()->all()];
    }

    private function sameVendor(Expense $e, string $merchant): bool
    {
        $a = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $e->vendor?->name));
        $b = strtolower(preg_replace('/[^a-z0-9]/i', '', $merchant));

        return $a === '' || $b === '' || str_contains($a, $b) || str_contains($b, $a);
    }

    /** @return array<string,int> category → expense account id (remembered first, then keyword guess) */
    public function suggestCategories(Business $b, array $categories): array
    {
        $saved = (array) (($b->expense_import_mappings ?? [])['categories'] ?? []);
        $accounts = ExpenseService::expenseAccounts($b);
        $other = $accounts->first(fn ($a) => preg_match('/other/i', $a->name)) ?? $accounts->first();
        $out = [];
        foreach ($categories as $c) {
            $name = $c['name'];
            if (isset($saved[$name]) && $accounts->contains('id', $saved[$name])) { $out[$name] = (int) $saved[$name]; continue; }
            $guess = null;
            foreach (self::CATEGORY_HINTS as $kw => $acctPattern) {
                if (preg_match($kw, $name) && ($guess = $accounts->first(fn ($a) => preg_match($acctPattern, $a->name)))) break;
            }
            $out[$name] = ($guess ?? $other)?->id;
        }

        return $out;
    }

    /** @return array<string,int> source account → funding account id */
    public function suggestAccounts(Business $b, array $sourceAccounts): array
    {
        $saved = (array) (($b->expense_import_mappings ?? [])['accounts'] ?? []);
        $funding = ExpenseService::fundingAccounts($b);
        $card = $funding->firstWhere('subtype', 'credit_card') ?? $funding->first();
        $bank = $funding->firstWhere('subtype', 'bank') ?? $funding->first();
        $out = [];
        foreach ($sourceAccounts as $a) {
            $name = $a['name'];
            $out[$name] = isset($saved[$name]) && $funding->contains('id', $saved[$name]) ? (int) $saved[$name]
                : (preg_match('/checking|savings|bank|debit/i', $name) ? $bank?->id : $card?->id);
        }

        return $out;
    }

    /**
     * Create expenses for the chosen rows. $opts: include (row numbers), categories (name→account id), accounts (name→account id),
     * client_id, project_id, entity (only rows for this business entity), remember (bool).
     */
    public function commit(Business $b, string $csv, string $filename, array $opts): ImportBatch
    {
        $p = $this->preview($b, $csv);
        $include = array_flip(array_map('intval', $opts['include'] ?? []));
        $catAllowed = ExpenseService::expenseAccounts($b)->keyBy('id');
        $fundAllowed = ExpenseService::fundingAccounts($b)->keyBy('id');
        $rows = array_values(array_filter($p['rows'], fn ($r) => isset($include[$r['n']]) && in_array($r['status'], ['new', 'match', 'pending'], true)
            && (empty($opts['entity']) || $r['entity'] === $opts['entity'])));
        if (! $rows) throw new DomainException('Select at least one row to import.');

        $batch = new ImportBatch(['kind' => 'expense_csv', 'filename' => mb_substr($filename, 0, 255), 'row_count' => count($p['rows']),
            'mapping' => ['categories' => $opts['categories'] ?? [], 'accounts' => $opts['accounts'] ?? []], 'created_by' => $this->ctx->user?->id]);
        $batch->business_id = $b->id;
        $batch->save();
        $imported = $errors = 0;
        $vendors = [];
        foreach ($rows as $r) {
            $acct = $catAllowed[(int) ($opts['categories'][$r['category']] ?? 0)] ?? null;
            $fund = $fundAllowed[(int) ($opts['accounts'][$r['account']] ?? 0)] ?? null;
            if (! $acct || ! $fund) { $errors++; continue; }
            try {
                DB::transaction(function () use ($b, $r, $acct, $fund, $opts, $batch, &$vendors) {
                    $key = strtolower($r['merchant']);
                    $vendors[$key] ??= $this->vendor($b, $r['merchant'], $acct->id);
                    $this->expenses->create($b, [
                        'kind' => 'expense', 'source' => 'import', 'import_batch_id' => $batch->id, 'external_id' => $r['external_id'],
                        'vendor_id' => $vendors[$key]?->id, 'expense_date' => $r['date'], 'payment_account_id' => $fund->id, 'payment_method' => 'card',
                        'description' => $r['notes'] ?: $r['merchant'], 'client_id' => $opts['client_id'] ?? null, 'project_id' => $opts['project_id'] ?? null,
                        'import_meta' => array_filter(['merchant' => $r['merchant'], 'category' => $r['category'], 'account' => $r['account'],
                            'statement' => $r['statement'], 'tags' => $r['tags'], 'entity' => $r['entity'], 'file' => $batch->filename]),
                        'lines' => [['account_id' => $acct->id, 'amount_cents' => $r['cents'], 'description' => $r['statement'] ?: null, 'billable' => false]],
                    ]);
                });
                $imported++;
            } catch (DomainException $e) {
                $errors++;
            }
        }
        $batch->update(['imported_count' => $imported, 'error_count' => $errors, 'duplicate_count' => count(array_filter($p['rows'], fn ($r) => in_array($r['status'], ['imported', 'match'], true)))]);
        if (! empty($opts['remember'])) {
            $m = (array) ($b->expense_import_mappings ?? []);
            $m['categories'] = array_merge((array) ($m['categories'] ?? []), array_map('intval', array_filter($opts['categories'] ?? [])));
            $m['accounts'] = array_merge((array) ($m['accounts'] ?? []), array_map('intval', array_filter($opts['accounts'] ?? [])));
            $b->forceFill(['expense_import_mappings' => $m])->save();
        }
        Audit::log($b, 'expenses.imported', $batch, null, ['file' => $filename, 'imported' => $imported, 'errors' => $errors]);

        return $batch;
    }

    private function vendor(Business $b, string $name, int $defaultAccountId): ?Vendor
    {
        $name = trim($name);
        if ($name === '') return null;
        $v = Vendor::where('business_id', $b->id)->whereRaw('lower(name) = ?', [strtolower($name)])->first();
        if ($v) return $v;
        $v = new Vendor(['name' => mb_substr($name, 0, 200), 'default_account_id' => $defaultAccountId]);
        $v->business_id = $b->id;
        $v->save();

        return $v;
    }

    /** Void every expense from a batch that hasn't since been edited or billed. */
    public function undo(ImportBatch $batch): array
    {
        $voided = $kept = 0;
        foreach (Expense::where('business_id', $batch->business_id)->where('import_batch_id', $batch->id)->whereNull('voided_at')->get() as $e) {
            if ($e->edited_at) { $kept++; continue; }
            try {
                $this->expenses->void($e, 'Import undone');
                $voided++;
            } catch (DomainException) {
                $kept++;
            }
        }
        $batch->update(['status' => 'undone', 'undone_at' => now()]);
        Audit::log($batch->business_id, 'expenses.import_undone', $batch, null, ['voided' => $voided, 'kept' => $kept]);

        return ['voided' => $voided, 'kept' => $kept];
    }
}
