<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 50px 44px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1e293b; }
    h1 { font-size: 18px; margin: 0; } table { width: 100%; border-collapse: collapse; } thead { display: table-header-group; }
    .tbl th { background: #f1f5f9; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; } .tbl td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; } .font-semibold { font-weight: bold; } .card { margin-top: 12px; } .muted { color: #64748b; }
</style></head><body>
<table><tr><td>@if ($logo)<img src="{{ $logo }}" style="max-height:48px; max-width:160px;" alt="">@else<strong>{{ $business->name() }}</strong>@endif
    <div>{{ implode(', ', $business->addressLines()) }}</div></td>
    <td style="text-align:right"><h1>STATEMENT</h1><div>{{ $from }} to {{ $to }}</div></td></tr></table>
<div style="margin-top: 14px;"><div class="muted">Statement for</div><strong>{{ $client->legal_name ?: $client->display_name }}</strong>
    @foreach ($client->billingAddressLines() as $l)<div>{{ $l }}</div>@endforeach</div>
@include('clients._statement-table')
<p class="muted" style="margin-top: 10px;">Balance due: <strong>{{ \App\Support\Money::format($closing) }}</strong>. Questions? {{ $business->public_email }}</p>
</body></html>
