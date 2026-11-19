@extends('layouts.app')
@section('title', 'Products & Services')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3"><h1>Products &amp; Services</h1>
    @if ($ctx->can('catalog.manage'))<button type="button" class="btn btn-primary" data-dialog-open="product-new">Add item</button>@endif
</div>
<p class="mt-1 text-sm text-slate-500">Catalog changes only affect future drafts. Issued invoices keep their original names, prices and tax.</p>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card overflow-x-auto lg:col-span-2">
        <table class="tbl">
            <thead><tr><th>Item</th><th>Type</th><th class="num">Price</th><th>Income account</th><th>Tax</th><th>Status</th>@if ($ctx->can('catalog.manage'))<th><span class="sr-only">Actions</span></th>@endif</tr></thead>
            <tbody>
            @forelse ($products as $p)
                <tr @class(['opacity-60' => ! $p->active])>
                    <td>
                        @if ($ctx->can('catalog.manage'))
                            <button type="button" class="text-left font-medium text-brand-700 hover:underline" data-dialog-open="product-{{ $p->id }}">{{ $p->name }}</button>
                        @else <span class="font-medium">{{ $p->name }}</span> @endif
                        @if ($p->sku)<div class="text-xs text-slate-500">{{ $p->sku }}</div>@endif
                    </td>
                    <td>{{ ucfirst($p->kind) }}</td>
                    <td class="num">${{ number_format((float) $p->default_price, 2) }}{{ $p->unit ? ' / '.$p->unit : '' }}</td>
                    <td>{{ $incomeAccounts->firstWhere('id', $p->income_account_id)?->label() }}</td>
                    <td>{{ $taxRates->firstWhere('id', $p->tax_rate_id)?->name ?? '—' }}</td>
                    <td>@include('partials.status', ['s' => $p->active ? 'active' : 'archived'])</td>
                    @if ($ctx->can('catalog.manage'))<td class="text-right"><button type="button" class="btn btn-sm" data-dialog-open="product-{{ $p->id }}">Edit</button></td>@endif
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty m-4">No items yet. Ad hoc lines are always allowed on invoices.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="space-y-4">
        <div class="card"><div class="card-head"><h2>Tax rates</h2></div>
            <div class="card-body space-y-2 text-sm">
                <p class="hint">Exclusive percentage taxes only. Rates are configured by you — automated jurisdiction lookup is not provided.</p>
                @foreach ($taxRates as $t)
                    <form method="post" action="{{ $bu }}/tax-rates/{{ $t->id }}" class="flex items-center gap-2">@csrf
                        <input class="input input-sm" name="name" value="{{ $t->name }}" aria-label="Tax rate name" @disabled(! $ctx->can('catalog.manage'))>
                        <span class="whitespace-nowrap">{{ rtrim(rtrim((string) $t->rate, '0'), '.') }}%</span>
                        <label class="flex items-center gap-1"><input type="hidden" name="active" value="0"><input class="check" type="checkbox" name="active" value="1" @checked($t->active) @disabled(! $ctx->can('catalog.manage'))> Active</label>
                        @if ($ctx->can('catalog.manage'))<button class="btn btn-sm">Save</button>@endif
                    </form>
                @endforeach
                @if ($ctx->can('catalog.manage'))
                <form method="post" action="{{ $bu }}/tax-rates" class="flex items-end gap-2 border-t border-slate-100 pt-2">@csrf
                    <div><label class="label" for="tr_name">Name</label><input class="input input-sm" id="tr_name" name="name" required></div>
                    <div><label class="label" for="tr_rate">Rate %</label><input class="input input-sm w-20" id="tr_rate" name="rate" type="number" step="0.0001" min="0" max="30" required></div>
                    <button class="btn btn-sm btn-primary">Add</button>
                </form>
                @endif
            </div>
        </div>
    </div>
</div>
@if ($ctx->can('catalog.manage'))
    @include('catalog._product-dialog', ['p' => new \App\Models\Product(['kind' => 'service']), 'action' => $bu.'/catalog'])
    @foreach ($products as $p)
        @include('catalog._product-dialog', ['p' => $p, 'action' => $bu.'/catalog/'.$p->id])
    @endforeach
@endif
@endsection
