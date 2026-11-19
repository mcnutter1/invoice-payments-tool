<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 40px 36px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #1e293b; }
    h1 { font-size: 16px; margin: 0; } h2 { font-size: 11px; margin: 0; } h3 { font-size: 9.5px; margin: 6px 0 2px; }
    .meta { color: #64748b; margin: 4px 0 12px; }
    table { width: 100%; border-collapse: collapse; } th { text-align: left; background: #f1f5f9; font-size: 8px; text-transform: uppercase; }
    th, td { padding: 3px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; } .font-semibold, .total td { font-weight: bold; } .text-slate-500, .text-xs, .hint { color: #64748b; font-size: 8px; }
    .card { margin-top: 12px; page-break-inside: auto; } .card-head { padding: 4px 0; border-bottom: 2px solid #1f5f8b; margin-bottom: 4px; }
    .card-head span { float: right; } .card-body { padding: 4px 0; }
    .grid { display: block; } .metric { display: inline-block; width: 23%; vertical-align: top; border: 1px solid #e2e8f0; padding: 6px; margin-right: 1%; }
    .metric-label { color: #64748b; font-size: 8px; } .metric-value { font-size: 13px; font-weight: bold; } .metric-note { color: #64748b; font-size: 7.5px; }
    .alert { border: 1px solid #f59e0b; background: #fffbeb; padding: 6px; margin-top: 10px; }
    details summary { list-style: none; } dl.dl dt { color: #64748b; } dl.dl dd { margin: 0 0 3px 0; }
    ul { margin: 2px 0; padding-left: 14px; } .text-slate-400 { color: #94a3b8; } .text-red-700 { color: #b91c1c; } .text-emerald-700 { color: #047857; } .text-amber-700 { color: #b45309; }
</style></head><body>
<h1>Tax estimate · {{ $e['year'] }}</h1>
<div class="meta">{{ $business->legal_name ?: $business->name() }} · {{ $e['entity'][0] }} ({{ $e['entity'][1] }}) · {{ $e['profile']['state'] }} · {{ ucfirst($e['profile']['tax_method']) }} method ·
    {{ $e['projecting'] ? 'Actual through '.$e['as_of']->format('M j, Y').' plus expected through Dec 31' : 'Full-year actual' }} · Generated {{ $generatedAt->format('Y-m-d H:i T') }}</div>
@include('reports.tax._body', ['e' => $e, 'print' => true])
</body></html>
