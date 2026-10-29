@php $M = fn ($c) => \App\Support\Money::format((int) $c); $range = 'from='.$meta['from'].'&to='.$meta['to']; @endphp
<table class="tbl">
    <tbody>
        <tr><td>Invoices issued in period</td><td class="num"><a href="{{ $bu }}/invoices?{{ $range }}">{{ $data['issued_count'] }}</a></td></tr>
        <tr><td>Total invoiced (incl. tax)</td><td class="num">{{ $M($data['issued_total']) }}</td></tr>
        <tr><td>Payments received in period (confirmed, net of reversals)</td><td class="num"><a href="{{ $bu }}/payments">{{ $M($data['collected']) }}</a></td></tr>
        <tr><td>Outstanding now</td><td class="num"><a href="{{ $bu }}/invoices?status=open">{{ $M($data['outstanding']) }}</a></td></tr>
        <tr><td>Overdue now</td><td class="num"><a href="{{ $bu }}/invoices?status=overdue">{{ $M($data['overdue']) }}</a></td></tr>
        <tr><td>Voided in period</td><td class="num">{{ $data['voided_count'] }}</td></tr>
    </tbody>
</table>
<p class="hint p-4">Client payment reports that have not been confirmed are not included.</p>
