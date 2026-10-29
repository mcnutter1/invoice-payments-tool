<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111827; }
    .slip { border: 2px dashed #94a3b8; padding: 16px; margin-top: 20px; } td { padding: 4px 0; } .lbl { color: #6b7280; width: 35%; }
</style></head><body>
<h2>Remittance slip</h2>
<p>Please include this slip with your check.</p>
<div class="slip">
    <table style="width:100%">
        <tr><td class="lbl">Make check payable to</td><td><strong>{{ $instructions['check']['payee'] }}</strong></td></tr>
        <tr><td class="lbl">Mail to</td><td style="white-space: pre-line;">{{ $instructions['check']['address'] }}</td></tr>
        <tr><td class="lbl">Invoice</td><td>{{ $invoice->number }}</td></tr>
        <tr><td class="lbl">From</td><td>{{ $client['legal_name'] ?? null ?: ($client['display_name'] ?? '') }}</td></tr>
        <tr><td class="lbl">Amount due</td><td><strong>{{ \App\Support\Money::format($invoice->balance_cents) }}</strong></td></tr>
        <tr><td class="lbl">Amount enclosed</td><td>$ ______________</td></tr>
        <tr><td class="lbl">Check number</td><td>______________</td></tr>
        <tr><td class="lbl">Memo</td><td>Invoice {{ $invoice->number }}</td></tr>
    </table>
</div>
</body></html>
