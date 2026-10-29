<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\CashProjection;
use App\Services\Accounting\Ledger;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function accounts()
    {
        $b = $this->biz();
        $balances = app(\App\Services\Reports\ReportService::class)->balances($b, null, $b->localToday()->toDateString());

        return view('accounting.accounts', [
            'accounts' => ChartAccount::where('business_id', $b->id)->orderBy('code')->get(),
            'balances' => $balances,
        ]);
    }

    public function storeAccount(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_accounts', 'code')->where('business_id', $b->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ChartAccount::TYPES)],
            'subtype' => ['nullable', Rule::in(['operating', 'cogs', 'other', 'fixed_asset', 'current_asset', 'loan', 'owner', 'contra', 'bank', 'credit_card'])],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $a = ChartAccount::create($data + ['business_id' => $b->id]);
        if (in_array($a->subtype, ['bank', 'credit_card'], true)) {
            app(\App\Services\Banking\BankingService::class)->linkAccount($b, $a);
        }
        Audit::log($b, 'account.created', $a);

        return back()->with('status', 'Account added.');
    }

    public function updateAccount(Request $request, int $id)
    {
        $a = ChartAccount::where('business_id', $this->biz()->id)->findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');
        if (! $data['is_active'] && $a->system_key) throw new DomainException('System accounts cannot be archived.');
        $before = $a->only(['name', 'description', 'is_active']);
        $a->update($data);
        [$bf, $af] = Audit::diff($before, $a->only(['name', 'description', 'is_active']));
        Audit::log($this->biz(), 'account.updated', $a, $bf, $af);

        return back()->with('status', 'Account saved. Accounts with history are archived, never deleted.');
    }

    public function journals(Request $request)
    {
        $b = $this->biz();
        $q = JournalEntry::with('lines.account')->where('business_id', $b->id);
        if ($request->query('kind') === 'manual') $q->where('kind', 'manual');
        if ($from = $request->query('from')) $q->whereDate('entry_date', '>=', $from);
        if ($to = $request->query('to')) $q->whereDate('entry_date', '<=', $to);

        return view('accounting.journals', ['entries' => $q->orderByDesc('entry_date')->orderByDesc('id')->paginate(30)->withQueryString()]);
    }

    public function entry(int $id)
    {
        $e = JournalEntry::with('lines.account', 'lines.client', 'lines.vendor', 'poster')->where('business_id', $this->biz()->id)->findOrFail($id);

        return view('accounting.entry', ['entry' => $e, 'reversedBy' => $e->reversedBy()]);
    }

    public function createJournal()
    {
        return view('accounting.journal-form', ['accounts' => ChartAccount::where('business_id', $this->biz()->id)->where('is_active', true)->orderBy('code')->get()]);
    }

    public function storeJournal(Request $request, Ledger $ledger, CashProjection $projection)
    {
        $b = $this->biz();
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);
        $lines = collect($data['lines'])->filter(fn ($l) => ! empty($l['account_id']) && (($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0))
            ->map(fn ($l) => ['account' => (int) $l['account_id'], 'debit' => $this->cents($l['debit'] ?? 0), 'credit' => $this->cents($l['credit'] ?? 0), 'description' => $l['description'] ?? null])->values()->all();
        $controls = ChartAccount::where('business_id', $b->id)->whereIn('system_key', ['ar', 'ap'])->pluck('id')->all();
        foreach ($lines as $l) {
            if (in_array($l['account'], $controls, true)) throw new DomainException('A/R and A/P are control accounts. Use invoices, credit notes, payments or bills instead.');
        }
        try {
            $entry = DB::transaction(function () use ($ledger, $b, $data, $lines, $projection) {
                $entry = $ledger->post($b, 'manual:'.bin2hex(random_bytes(8)), $data['entry_date'], $data['memo'], $lines, ['kind' => 'manual', 'source_type' => 'manual', 'reason' => $data['reason']]);
                if ($projection->touchesCash($entry)) $projection->recordDirect($entry);

                return $entry;
            });
        } catch (\InvalidArgumentException $e) {
            throw new DomainException('Debits and credits must be equal and use accounts from this business.');
        }
        Audit::log($b, 'journal.manual_posted', $entry, null, ['lines' => count($lines)], $data['reason']);

        return $this->to('/journals/'.$entry->id, 'Journal posted.');
    }

    public function reverseJournal(Request $request, int $id, Ledger $ledger, CashProjection $projection)
    {
        $b = $this->biz();
        $e = JournalEntry::where('business_id', $b->id)->findOrFail($id);
        if ($e->kind !== 'manual') throw new DomainException('Automatic entries are corrected from their source document (void, credit, reverse payment).');
        if ($e->reversal_of_id || $e->reversedBy()) throw new DomainException('Already reversed.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'date' => ['nullable', 'date']]);
        $rev = DB::transaction(function () use ($ledger, $e, $data, $projection, $b) {
            $rev = $ledger->reverse($e, 'manual:'.$e->id.':reverse', $data['date'] ?? $ledger->openDate($b, $b->localToday()), $data['reason']);
            if ($projection->touchesCash($rev)) $projection->recordDirect($rev);

            return $rev;
        });
        Audit::log($b, 'journal.reversed', $e, null, ['reversal_id' => $rev->id], $data['reason']);

        return $this->to('/journals/'.$rev->id, 'Reversing entry posted.');
    }

    public function periods()
    {
        $b = $this->biz();

        return view('accounting.periods', ['history' => AccountingPeriod::with('actor')->where('business_id', $b->id)->latest('id')->get()]);
    }

    public function close(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate(['closed_through' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['nullable', 'string', 'max:500']]);
        $date = Carbon::parse($data['closed_through']);
        if ($b->books_closed_through && $date->lte($b->books_closed_through)) {
            throw new DomainException('Books are already closed through '.$b->books_closed_through->toDateString().'. Reopen to move the date back.');
        }
        DB::transaction(function () use ($b, $date, $data) {
            $b->update(['books_closed_through' => $date->toDateString()]);
            AccountingPeriod::create(['business_id' => $b->id, 'action' => 'close', 'closed_through' => $date->toDateString(), 'actor_user_id' => $this->ctx()->user->id, 'reason' => $data['reason'] ?? null]);
            Audit::log($b, 'books.closed', $b, null, ['closed_through' => $date->toDateString()], $data['reason'] ?? null);
        });

        return back()->with('status', 'Books closed through '.$date->format('M j, Y').'.');
    }

    public function reopen(Request $request)
    {
        $b = $this->biz();
        $data = $request->validate(['closed_through' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:500']]);
        $new = $data['closed_through'] ?? null;
        if ($new && $b->books_closed_through && Carbon::parse($new)->gte($b->books_closed_through)) {
            throw new DomainException('Choose an earlier date to reopen.');
        }
        DB::transaction(function () use ($b, $new, $data) {
            $old = $b->books_closed_through?->toDateString();
            $b->update(['books_closed_through' => $new]);
            AccountingPeriod::create(['business_id' => $b->id, 'action' => 'reopen', 'closed_through' => $new, 'actor_user_id' => $this->ctx()->user->id, 'reason' => $data['reason']]);
            Audit::log($b, 'books.reopened', $b, ['closed_through' => $old], ['closed_through' => $new], $data['reason']);
        });

        return back()->with('status', 'Period reopened.');
    }
}
