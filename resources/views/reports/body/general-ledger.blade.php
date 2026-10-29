@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
@forelse ($data['accounts'] as $a)
    <table class="tbl">
        <thead><tr><th colspan="{{ $a['cash'] ? 4 : 6 }}">{{ $a['account']->label() }} {{ $a['cash'] ? '· cash-basis recognition events' : '' }}</th></tr>
        @if ($a['cash'])<tr><th>Date</th><th>Source</th><th>Description</th><th class="num">Amount</th></tr>
        @else<tr><th>Date</th><th>Entry</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr>@endif
        </thead>
        <tbody>
        @if (! $a['cash'])<tr><td colspan="5" class="text-slate-500">Opening balance</td><td class="num">{{ $M($a['opening']) }}</td></tr>@endif
        @php $run = $a['opening']; @endphp
        @foreach ($a['lines'] as $l)
            @if ($a['cash'])
                <tr><td>{{ $l->event_date->format('Y-m-d') }}</td><td class="text-xs">{{ $l->invoice_id ? 'Invoice receipt' : ($l->expense_id ? 'Expense payment' : 'Cash entry') }}</td><td>{{ $l->description }}</td><td class="num">{{ $M($l->amount_cents) }}</td></tr>
            @else
                @php $run += $l->debit_cents - $l->credit_cents; @endphp
                <tr><td>{{ $l->entry_date->format('Y-m-d') }}</td><td><a href="{{ $bu }}/journals/{{ $l->journal_entry_id }}">#{{ $l->journal_entry_id }}</a></td><td>{{ $l->entry->memo }}</td>
                    <td class="num">{{ $l->debit_cents ? $M($l->debit_cents) : '' }}</td><td class="num">{{ $l->credit_cents ? $M($l->credit_cents) : '' }}</td><td class="num">{{ $M($run) }}</td></tr>
            @endif
        @endforeach
        <tr class="total"><td colspan="{{ $a['cash'] ? 3 : 5 }}" class="font-semibold">{{ $a['cash'] ? 'Total recognized' : 'Closing balance (debit − credit)' }}</td><td class="num font-semibold">{{ $M($a['closing']) }}</td></tr>
        </tbody>
    </table>
@empty
    <div class="empty m-4">No activity in this period.</div>
@endforelse
