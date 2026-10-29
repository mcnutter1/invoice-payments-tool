<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 40px 36px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1e293b; }
    h1 { font-size: 16px; margin: 0; } .meta { color: #64748b; margin: 4px 0 12px; }
    table { width: 100%; border-collapse: collapse; } th { text-align: left; background: #f1f5f9; font-size: 8.5px; text-transform: uppercase; }
    th, td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; } .font-semibold, .total td { font-weight: bold; } .text-slate-500 { color: #64748b; }
    .section td { background: #f8fafc; font-weight: bold; } a { color: inherit; text-decoration: none; } .chip { font-size: 8px; color: #475569; }
    .p-4 { padding: 8px 0; } .hint { color: #64748b; font-size: 8.5px; }
</style></head><body>
<h1>{{ $meta['title'] }}</h1>
<div class="meta">{{ $business->name() }} · USD ·
    @if (! in_array($meta['report'], ['balance-sheet', 'trial-balance', 'ar-aging', 'ap-aging'])) {{ $meta['from'] }} to {{ $meta['to'] }} @else As of {{ $meta['asOf'] }} @endif
    · {{ ucfirst($meta['basis']) }} basis · Generated {{ $meta['generatedAt']->format('Y-m-d H:i T') }}</div>
@include('reports.body.'.$meta['report'], ['data' => $data, 'meta' => $meta, 'print' => true, 'bu' => '/b/'.$business->ulid])
</body></html>
