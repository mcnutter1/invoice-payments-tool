@php $M = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<table class="tbl">
    <tbody>
        <tr><td>Opening liability</td><td class="num">{{ $M($data['opening']) }}</td></tr>
        <tr><td>+ Tax on invoices issued</td><td class="num">{{ $M($data['invoiced']) }}</td></tr>
        <tr><td>− Tax reversed by credit notes</td><td class="num">{{ $M($data['credited']) }}</td></tr>
        <tr><td>− Payments to tax authorities &amp; adjustments</td><td class="num">{{ $M($data['payments_and_adjustments']) }}</td></tr>
        <tr class="total"><td class="font-semibold">Closing liability (accrual)</td><td class="num font-semibold">{{ $M($data['closing']) }}</td></tr>
        <tr><td>Tax collected in cash this period (management view)</td><td class="num">{{ $M($data['cash_collected']) }}</td></tr>
    </tbody>
</table>
<table class="tbl mt-4">
    <thead><tr><th>Tax</th><th class="num">Rate</th><th class="num">Taxable sales</th><th class="num">Tax</th></tr></thead>
    <tbody>
    @forelse ($data['by_rate'] as $r)<tr><td>{{ $r->name }}</td><td class="num">{{ rtrim(rtrim((string) $r->rate, '0'), '.') }}%</td><td class="num">{{ $M($r->taxable) }}</td><td class="num">{{ $M($r->tax) }}</td></tr>
    @empty <tr><td colspan="4" class="text-slate-500">No taxable sales.</td></tr> @endforelse
    </tbody>
</table>
<p class="hint p-4">Supporting schedule only. This app does not determine jurisdictions or file returns. Record remittances as a journal entry debiting Sales Tax Payable.</p>
