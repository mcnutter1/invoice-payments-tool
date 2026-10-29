<div class="card mt-4 overflow-x-auto">
<table class="tbl">
    <thead><tr><th>Date</th><th>Activity</th><th class="num">Charges</th><th class="num">Payments &amp; credits</th><th class="num">Balance</th></tr></thead>
    <tbody>
        @php $running = $opening; @endphp
        <tr><td>{{ $from }}</td><td>Opening balance</td><td></td><td></td><td class="num">{{ \App\Support\Money::format($opening) }}</td></tr>
        @foreach ($lines as $l)
            @php $running += $l->debit_cents - $l->credit_cents; @endphp
            <tr><td>{{ $l->entry_date->format('Y-m-d') }}</td><td>{{ $l->entry->memo }}</td>
                <td class="num">{{ $l->debit_cents ? \App\Support\Money::format($l->debit_cents) : '' }}</td>
                <td class="num">{{ $l->credit_cents ? \App\Support\Money::format($l->credit_cents) : '' }}</td>
                <td class="num">{{ \App\Support\Money::format($running) }}</td></tr>
        @endforeach
        <tr><td>{{ $to }}</td><td class="font-semibold">Closing balance</td><td></td><td></td><td class="num font-semibold">{{ \App\Support\Money::format($closing) }}</td></tr>
    </tbody>
</table>
</div>
