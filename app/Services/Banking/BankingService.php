<?php

namespace App\Services\Banking;

use App\Exceptions\DomainException;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MoneyMovement;
use App\Models\ReconciliationSession;
use App\Services\Accounting\CashProjection;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use App\Support\Context;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BankingService
{
    public const MAX_ROWS = 5000;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly CashProjection $projection,
        private readonly Context $ctx,
    ) {}

    public function createAccount(Business $business, array $data): BankAccount
    {
        return DB::transaction(function () use ($business, $data) {
            $isCard = ($data['type'] ?? 'bank') === 'credit_card';
            $base = $isCard ? 2310 : 1010;
            $code = $base;
            while (ChartAccount::where('business_id', $business->id)->where('code', (string) $code)->exists()) $code++;
            $chart = ChartAccount::create([
                'business_id' => $business->id,
                'code' => (string) $code,
                'name' => $data['name'],
                'type' => $isCard ? 'liability' : 'asset',
                'subtype' => $isCard ? 'credit_card' : 'bank',
            ]);
            $acct = $this->linkAccount($business, $chart, $data);

            return $acct;
        });
    }

    public function linkAccount(Business $business, ChartAccount $chart, array $data = []): BankAccount
    {
        $acct = new BankAccount([
            'chart_account_id' => $chart->id,
            'name' => $data['name'] ?? $chart->name,
            'institution' => $data['institution'] ?? null,
            'last4' => $data['last4'] ?? null,
            'opening_balance_cents' => (int) ($data['opening_balance_cents'] ?? 0),
            'opening_date' => $data['opening_date'] ?? null,
        ]);
        $acct->business_id = $business->id;
        $acct->save();
        $opening = (int) ($data['opening_balance_cents'] ?? 0);
        if ($opening !== 0) {
            $date = $data['opening_date'] ?? $business->localToday()->toDateString();
            $obe = $business->account('opening_balance_equity');
            $lines = $chart->type === 'asset'
                ? [['account' => $chart, 'debit' => $opening], ['account' => $obe, 'credit' => $opening]]
                : [['account' => $obe, 'debit' => $opening], ['account' => $chart, 'credit' => $opening]];
            $this->ledger->post($business, 'bank_account:'.$acct->id.':opening', $date, 'Opening balance · '.$acct->name, $lines, ['kind' => 'opening', 'source_type' => 'bank_account', 'source_id' => $acct->id]);
        }
        Audit::log($business, 'bank_account.created', $acct);

        return $acct;
    }

    /** @return array{header:list<string>, rows:list<list<string>>} */
    public function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $contents);
        rewind($fh);
        $header = null;
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($r === [null] || $r === ['']) continue;
            $r = array_map(fn ($v) => trim((string) $v), $r);
            if ($header === null) { $header = $r; continue; }
            $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) throw new DomainException('CSV has more than '.self::MAX_ROWS.' rows. Split the file.');
        }
        fclose($fh);
        if (! $header) throw new DomainException('CSV file is empty.');

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Normalize rows with a column mapping.
     * mapping: date (int), description (int), amount (int|null), debit (int|null), credit (int|null), date_format, invert (bool)
     */
    public function normalize(BankAccount $account, array $rows, array $mapping): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $err = null;
            $rawDate = $r[$mapping['date']] ?? '';
            try {
                $date = ! empty($mapping['date_format'])
                    ? Carbon::createFromFormat('!'.$mapping['date_format'], $rawDate)
                    : Carbon::parse($rawDate);
            } catch (\Throwable) {
                $date = null;
                $err = 'Unreadable date "'.$rawDate.'"';
            }
            $desc = mb_substr($r[$mapping['description']] ?? '', 0, 250);
            try {
                if (($mapping['amount'] ?? '') !== '' && $mapping['amount'] !== null) {
                    $amt = Money::parse($this->cleanAmount($r[(int) $mapping['amount']] ?? ''));
                } else {
                    $debit = Money::parse($this->cleanAmount($r[(int) $mapping['debit']] ?? ''));
                    $credit = Money::parse($this->cleanAmount($r[(int) $mapping['credit']] ?? ''));
                    $amt = $credit - abs($debit);
                }
                if (! empty($mapping['invert'])) $amt = -$amt;
            } catch (\Throwable) {
                $amt = 0;
                $err ??= 'Unreadable amount';
            }
            if (! $err && $amt === 0) $err = 'Zero amount';
            $base = $account->id.'|'.($date?->toDateString()).'|'.$amt.'|'.strtolower(preg_replace('/\s+/', ' ', $desc));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $fp = hash('sha256', $base.'|'.$seen[$base]);
            $dupe = ! $err && BankTransaction::where('business_id', $account->business_id)->where('bank_account_id', $account->id)->where('fingerprint', $fp)->exists();
            $out[] = ['row' => $i + 2, 'date' => $date?->toDateString(), 'description' => $desc, 'amount_cents' => $amt, 'fingerprint' => $fp, 'error' => $err, 'duplicate' => $dupe];
        }

        return $out;
    }

    private function cleanAmount(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '0';
        $neg = str_starts_with($v, '(') && str_ends_with($v, ')');
        $v = str_replace(['(', ')', '$', ',', ' '], '', $v);

        return ($neg ? '-' : '').$v;
    }

    public function import(BankAccount $account, string $filename, array $normalized, array $mapping): ImportBatch
    {
        return DB::transaction(function () use ($account, $filename, $normalized, $mapping) {
            $batch = ImportBatch::create([
                'business_id' => $account->business_id,
                'kind' => 'bank_csv',
                'bank_account_id' => $account->id,
                'filename' => mb_substr($filename, 0, 200),
                'row_count' => count($normalized),
                'mapping' => $mapping,
                'created_by' => $this->ctx->user?->id,
            ]);
            $imported = $dupes = $errors = 0;
            foreach ($normalized as $n) {
                if ($n['error']) { $errors++; continue; }
                $exists = BankTransaction::where('business_id', $account->business_id)->where('bank_account_id', $account->id)->where('fingerprint', $n['fingerprint'])->exists();
                if ($exists) { $dupes++; continue; }
                BankTransaction::create([
                    'business_id' => $account->business_id,
                    'bank_account_id' => $account->id,
                    'import_batch_id' => $batch->id,
                    'posted_date' => $n['date'],
                    'description' => $n['description'] ?: '(no description)',
                    'amount_cents' => $n['amount_cents'],
                    'fingerprint' => $n['fingerprint'],
                ]);
                $imported++;
            }
            $batch->update(['imported_count' => $imported, 'duplicate_count' => $dupes, 'error_count' => $errors]);
            Audit::log($account->business_id, 'bank.imported', $batch, null, ['imported' => $imported, 'duplicates' => $dupes]);

            return $batch;
        });
    }

    public function undoBatch(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $active = BankTransaction::where('import_batch_id', $batch->id)->whereIn('status', ['matched', 'posted'])->count();
            if ($active) throw new DomainException("{$active} rows from this import are matched or posted. Undo those first.");
            BankTransaction::where('import_batch_id', $batch->id)->delete();
            $batch->update(['status' => 'undone', 'undone_at' => now()]);
            Audit::log($batch->business_id, 'bank.import_undone', $batch);
        });
    }

    public function suggestions(BankTransaction $txn)
    {
        $acct = BankAccount::findOrFail($txn->bank_account_id);
        $taken = BankTransaction::where('business_id', $txn->business_id)->whereNotNull('matched_journal_line_id')->pluck('matched_journal_line_id');
        $sign = $this->signFor($acct);

        return JournalLine::with('entry')->where('business_id', $txn->business_id)
            ->where('account_id', $acct->chart_account_id)
            ->whereBetween('entry_date', [$txn->posted_date->copy()->subDays(10)->toDateString(), $txn->posted_date->copy()->addDays(10)->toDateString()])
            ->whereNotIn('id', $taken)
            ->get()
            ->filter(fn ($l) => $sign * ($l->debit_cents - $l->credit_cents) === $txn->amount_cents)
            ->sortBy(fn ($l) => abs($l->entry_date->diffInDays($txn->posted_date)))
            ->values();
    }

    /** Asset accounts: inflow = debit. Credit cards: a charge (outflow, negative) = credit. */
    private function signFor(BankAccount $acct): int
    {
        return 1;
    }

    public function match(BankTransaction $txn, JournalLine $line): void
    {
        DB::transaction(function () use ($txn, $line) {
            $txn = BankTransaction::where('business_id', $txn->business_id)->lockForUpdate()->findOrFail($txn->id);
            if ($txn->status !== 'unmatched') throw new DomainException('Row is already handled.');
            $acct = BankAccount::findOrFail($txn->bank_account_id);
            if ($line->business_id !== $txn->business_id || $line->account_id !== $acct->chart_account_id) throw new DomainException('Ledger line is not on this account.');
            if ($line->debit_cents - $line->credit_cents !== $txn->amount_cents) throw new DomainException('Amounts do not match.');
            if (BankTransaction::where('matched_journal_line_id', $line->id)->exists()) throw new DomainException('Ledger line is already matched.');
            $txn->update(['status' => 'matched', 'matched_journal_line_id' => $line->id, 'matched_by' => $this->ctx->user?->id, 'matched_at' => now()]);
            Audit::log($txn->business_id, 'bank.matched', $txn, null, ['journal_line_id' => $line->id]);
        });
    }

    /** Create a new posting from an imported row (categorize, or transfer to another balance-sheet account). */
    public function categorize(BankTransaction $txn, int $accountId, ?string $memo = null, ?int $vendorId = null, ?int $clientId = null): JournalEntry
    {
        return DB::transaction(function () use ($txn, $accountId, $memo, $vendorId, $clientId) {
            $txn = BankTransaction::where('business_id', $txn->business_id)->lockForUpdate()->findOrFail($txn->id);
            if ($txn->status !== 'unmatched') throw new DomainException('Row is already handled.');
            $business = Business::findOrFail($txn->business_id);
            $acct = BankAccount::findOrFail($txn->bank_account_id);
            $category = ChartAccount::where('business_id', $business->id)->findOrFail($accountId);
            if ($category->id === $acct->chart_account_id) throw new DomainException('Choose a different account.');
            if (in_array($category->system_key, ['ar', 'ap'], true)) throw new DomainException('Use Payments or Bills to record receivable/payable activity, then match.');
            $amt = $txn->amount_cents;
            $lines = $amt > 0
                ? [['account' => $acct->chart_account_id, 'debit' => $amt], ['account' => $category, 'credit' => $amt, 'vendor_id' => $vendorId, 'client_id' => $clientId]]
                : [['account' => $category, 'debit' => -$amt, 'vendor_id' => $vendorId, 'client_id' => $clientId], ['account' => $acct->chart_account_id, 'credit' => -$amt]];
            $entry = $this->ledger->post($business, 'bank_txn:'.$txn->id.':post', $txn->posted_date, $memo ?: $txn->description, $lines, ['source_type' => 'bank_transaction', 'source_id' => $txn->id]);
            $this->projection->recordDirect($entry);
            $bankLine = $entry->lines()->where('account_id', $acct->chart_account_id)->first();
            $txn->update(['status' => 'posted', 'created_journal_entry_id' => $entry->id, 'matched_journal_line_id' => $bankLine->id, 'matched_by' => $this->ctx->user?->id, 'matched_at' => now()]);
            Audit::log($business, 'bank.posted', $txn, null, ['account' => $category->label()]);

            return $entry;
        });
    }

    public function exclude(BankTransaction $txn): void
    {
        if ($txn->status !== 'unmatched') throw new DomainException('Row is already handled.');
        $txn->update(['status' => 'excluded', 'matched_by' => $this->ctx->user?->id, 'matched_at' => now()]);
        Audit::log($txn->business_id, 'bank.excluded', $txn);
    }

    public function undo(BankTransaction $txn): void
    {
        DB::transaction(function () use ($txn) {
            $txn = BankTransaction::where('business_id', $txn->business_id)->lockForUpdate()->findOrFail($txn->id);
            if ($txn->matched_journal_line_id && JournalLine::whereKey($txn->matched_journal_line_id)->whereNotNull('reconciliation_id')->exists()) {
                throw new DomainException('This row is part of a completed reconciliation. Undo the reconciliation first.');
            }
            $business = Business::findOrFail($txn->business_id);
            if ($txn->status === 'posted' && $txn->created_journal_entry_id) {
                $entry = JournalEntry::where('business_id', $business->id)->findOrFail($txn->created_journal_entry_id);
                $rev = $this->ledger->reverse($entry, 'bank_txn:'.$txn->id.':undo:'.now()->timestamp, $this->ledger->openDate($business, $txn->posted_date), 'Undo bank posting');
                $this->projection->recordDirect($rev);
            }
            $txn->update(['status' => 'unmatched', 'matched_journal_line_id' => null, 'created_journal_entry_id' => null, 'matched_by' => null, 'matched_at' => null]);
            Audit::log($business, 'bank.undone', $txn);
        });
    }

    public function recordMovement(Business $business, array $data): MoneyMovement
    {
        return DB::transaction(function () use ($business, $data) {
            $kind = $data['kind'];
            $amt = (int) $data['amount_cents'];
            if ($amt <= 0) throw new DomainException('Amount must be positive.');
            $acct = fn ($id) => ChartAccount::where('business_id', $business->id)->findOrFail($id);
            [$from, $to] = match ($kind) {
                'transfer' => [$acct($data['from_account_id']), $acct($data['to_account_id'])],
                'owner_contribution' => [$business->account('owner_contributions'), $acct($data['to_account_id'])],
                'owner_draw' => [$acct($data['from_account_id']), $business->account('owner_draws')],
                'loan_proceeds' => [$business->account('loans'), $acct($data['to_account_id'])],
                'loan_payment' => [$acct($data['from_account_id']), $business->account('loans')],
                default => throw new DomainException('Unknown movement type.'),
            };
            foreach ([$from, $to] as $a) {
                if (in_array($a->type, ['income', 'expense'], true)) throw new DomainException('Money movements never touch income or expense accounts.');
            }
            if ($from->id === $to->id) throw new DomainException('Choose two different accounts.');
            $mm = MoneyMovement::create([
                'business_id' => $business->id,
                'kind' => $kind,
                'movement_date' => $data['movement_date'],
                'amount_cents' => $amt,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'memo' => $data['memo'] ?? null,
                'created_by' => $this->ctx->user?->id,
            ]);
            $entry = $this->ledger->post($business, 'movement:'.$mm->id, $data['movement_date'], MoneyMovement::KINDS[$kind].($mm->memo ? ' · '.$mm->memo : ''), [
                ['account' => $to, 'debit' => $amt],
                ['account' => $from, 'credit' => $amt],
            ], ['source_type' => 'money_movement', 'source_id' => $mm->id]);
            $mm->journal_entry_id = $entry->id;
            $mm->save();
            Audit::log($business, 'movement.recorded', $mm, null, ['kind' => $kind, 'amount_cents' => $amt]);

            return $mm;
        });
    }

    // ----- Reconciliation -----

    public function startReconciliation(BankAccount $acct, string $statementDate, int $closing, ?int $opening = null): ReconciliationSession
    {
        if (ReconciliationSession::where('bank_account_id', $acct->id)->where('status', 'in_progress')->exists()) {
            throw new DomainException('Finish or cancel the reconciliation already in progress.');
        }
        $prev = ReconciliationSession::where('bank_account_id', $acct->id)->where('status', 'completed')->orderByDesc('statement_date')->first();

        return ReconciliationSession::create([
            'business_id' => $acct->business_id,
            'bank_account_id' => $acct->id,
            'statement_date' => $statementDate,
            'statement_opening_cents' => $opening ?? ($prev?->statement_closing_cents ?? 0),
            'statement_closing_cents' => $closing,
            'created_by' => $this->ctx->user?->id,
        ]);
    }

    public function reconcilableLines(ReconciliationSession $s)
    {
        $acct = BankAccount::findOrFail($s->bank_account_id);

        return JournalLine::with('entry')->where('business_id', $s->business_id)->where('account_id', $acct->chart_account_id)
            ->whereNull('reconciliation_id')->where('entry_date', '<=', $s->statement_date->toDateString())
            ->orderBy('entry_date')->get();
    }

    /** @param list<int> $lineIds */
    public function completeReconciliation(ReconciliationSession $s, array $lineIds): void
    {
        DB::transaction(function () use ($s, $lineIds) {
            $s = ReconciliationSession::where('business_id', $s->business_id)->lockForUpdate()->findOrFail($s->id);
            if ($s->status !== 'in_progress') throw new DomainException('Reconciliation is not in progress.');
            $acct = BankAccount::findOrFail($s->bank_account_id);
            $lines = JournalLine::where('business_id', $s->business_id)->where('account_id', $acct->chart_account_id)
                ->whereNull('reconciliation_id')->whereIn('id', $lineIds)->where('entry_date', '<=', $s->statement_date->toDateString())->lockForUpdate()->get();
            $chart = ChartAccount::find($acct->chart_account_id);
            $sign = $chart->type === 'asset' ? 1 : -1;
            $cleared = $lines->sum(fn ($l) => $sign * ($l->debit_cents - $l->credit_cents));
            $diff = $s->statement_closing_cents - ($s->statement_opening_cents + $cleared);
            if ($diff !== 0) {
                throw new DomainException('Difference is '.Money::format($diff).'. Cleared items must make the statement balance.');
            }
            JournalLine::whereIn('id', $lines->pluck('id'))->update(['reconciliation_id' => $s->id]);
            $s->update(['status' => 'completed', 'completed_by' => $this->ctx->user?->id, 'completed_at' => now()]);
            Audit::log($s->business_id, 'reconciliation.completed', $s, null, ['lines' => $lines->count()]);
        });
    }

    public function undoReconciliation(ReconciliationSession $s, string $reason): void
    {
        DB::transaction(function () use ($s, $reason) {
            $later = ReconciliationSession::where('bank_account_id', $s->bank_account_id)->where('status', 'completed')
                ->where('statement_date', '>', $s->statement_date)->exists();
            if ($later) throw new DomainException('Undo later reconciliations first.');
            JournalLine::where('reconciliation_id', $s->id)->update(['reconciliation_id' => null]);
            $s->update(['status' => 'undone']);
            Audit::log($s->business_id, 'reconciliation.undone', $s, null, null, $reason);
        });
    }
}
