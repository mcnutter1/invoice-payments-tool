<?php

namespace App\Services\Accounting;

use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Ledger
{
    public function __construct(private readonly Context $ctx) {}

    /**
     * Post a balanced journal entry exactly once per (business, sourceKey).
     *
     * @param  list<array{account:ChartAccount|int, debit?:int, credit?:int, description?:string, client_id?:int|null, vendor_id?:int|null, invoice_id?:int|null}>  $lines
     */
    public function post(Business $business, string $sourceKey, Carbon|string $date, string $memo, array $lines, array $opts = []): JournalEntry
    {
        $existing = JournalEntry::where('business_id', $business->id)->where('source_key', $sourceKey)->first();
        if ($existing) {
            return $existing;
        }
        $date = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
        $this->assertOpen($business, $date, $opts['allow_closed'] ?? false);

        $normalized = [];
        $dr = 0;
        $cr = 0;
        foreach ($lines as $l) {
            $accountId = $l['account'] instanceof ChartAccount ? $l['account']->id : (int) $l['account'];
            $d = (int) ($l['debit'] ?? 0);
            $c = (int) ($l['credit'] ?? 0);
            if ($d < 0) { $c += -$d; $d = 0; }
            if ($c < 0) { $d += -$c; $c = 0; }
            if ($d > 0 && $c > 0) {
                $net = $d - $c;
                [$d, $c] = $net >= 0 ? [$net, 0] : [0, -$net];
            }
            if ($d === 0 && $c === 0) continue;
            $dr += $d;
            $cr += $c;
            $normalized[] = [
                'account_id' => $accountId,
                'debit_cents' => $d,
                'credit_cents' => $c,
                'description' => isset($l['description']) ? mb_substr((string) $l['description'], 0, 255) : null,
                'client_id' => $l['client_id'] ?? null,
                'vendor_id' => $l['vendor_id'] ?? null,
                'invoice_id' => $l['invoice_id'] ?? null,
            ];
        }
        if (! $normalized || $dr !== $cr) {
            throw new InvalidArgumentException("Journal entry does not balance ({$dr} != {$cr})");
        }
        $ids = array_unique(array_column($normalized, 'account_id'));
        $valid = ChartAccount::where('business_id', $business->id)->whereIn('id', $ids)->count();
        if ($valid !== count($ids)) {
            throw new InvalidArgumentException('Journal references an account outside this business');
        }

        return DB::transaction(function () use ($business, $sourceKey, $date, $memo, $normalized, $opts) {
            $entry = JournalEntry::create([
                'business_id' => $business->id,
                'entry_date' => $date->toDateString(),
                'memo' => mb_substr($memo, 0, 255),
                'kind' => $opts['kind'] ?? 'auto',
                'source_type' => $opts['source_type'] ?? null,
                'source_id' => $opts['source_id'] ?? null,
                'source_key' => $sourceKey,
                'reversal_of_id' => $opts['reversal_of_id'] ?? null,
                'currency' => 'USD',
                'posted_by' => $this->ctx->user?->id,
                'reason' => $opts['reason'] ?? null,
            ]);
            foreach ($normalized as $n) {
                JournalLine::create($n + [
                    'business_id' => $business->id,
                    'journal_entry_id' => $entry->id,
                    'entry_date' => $date->toDateString(),
                ]);
            }

            return $entry;
        });
    }

    public function reverse(JournalEntry $entry, string $sourceKey, Carbon|string|null $date = null, ?string $reason = null): JournalEntry
    {
        $business = Business::findOrFail($entry->business_id);
        $date = $date ? Carbon::parse($date) : today();
        $lines = $entry->lines()->get()->map(fn (JournalLine $l) => [
            'account' => $l->account_id,
            'debit' => $l->credit_cents,
            'credit' => $l->debit_cents,
            'description' => 'Reversal: '.($l->description ?? ''),
            'client_id' => $l->client_id,
            'vendor_id' => $l->vendor_id,
            'invoice_id' => $l->invoice_id,
        ])->all();

        return $this->post($business, $sourceKey, $date, 'Reversal of #'.$entry->id.': '.$entry->memo, $lines, [
            'reversal_of_id' => $entry->id,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'reason' => $reason,
            'kind' => $entry->kind === 'manual' ? 'manual' : 'auto',
        ]);
    }

    public function assertOpen(Business $business, Carbon $date, bool $allowClosed = false): void
    {
        $closed = $business->fresh()->books_closed_through;
        if (! $allowClosed && $closed && $date->lte($closed)) {
            throw new ClosedPeriodException('Books are closed through '.$closed->toDateString().'. Use a date after the close or reopen the period.');
        }
    }

    /** Today, or the first open day if today is inside a closed period. */
    public function openDate(Business $business, ?Carbon $preferred = null): Carbon
    {
        $d = ($preferred ?? today())->copy();
        $closed = $business->books_closed_through;

        return ($closed && $d->lte($closed)) ? $closed->copy()->addDay() : $d;
    }
}
