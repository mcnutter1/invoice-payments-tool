@extends('layouts.app')
@section('title', 'Chart of accounts')
@section('content')
@include('accounting._tabs')
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card overflow-x-auto lg:col-span-2">
        <table class="tbl"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
        @foreach ($accounts->groupBy('type') as $type => $group)
            <tr><td colspan="5" class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">{{ ucfirst($type) }}</td></tr>
            @foreach ($group as $a)
                @php $bal = $balances[$a->id] ?? 0; $shown = $a->isDebitNormal() ? $bal : -$bal; @endphp
                <tr>
                    <td class="font-mono text-xs">{{ $a->code }}</td>
                    <td>
                        @if ($ctx->can('journals.manage'))
                        <details><summary class="cursor-pointer">{{ $a->name }} @if($a->system_key)<span class="chip chip-gray">system</span>@endif</summary>
                            <form method="post" action="{{ $bu }}/accounts/{{ $a->id }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
                                <input class="input input-sm w-48" name="name" value="{{ $a->name }}" aria-label="Account name">
                                <input class="input input-sm w-48" name="description" value="{{ $a->description }}" placeholder="Description" aria-label="Description">
                                @unless ($a->system_key)<label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_active" value="0"><input class="check" type="checkbox" name="is_active" value="1" @checked($a->is_active)> Active</label>@else<input type="hidden" name="is_active" value="1">@endunless
                                <button class="btn btn-sm">Save</button>
                            </form></details>
                        @else {{ $a->name }} @endif
                    </td>
                    <td class="text-xs">{{ $a->subtype }}</td>
                    <td class="num"><a href="{{ $bu }}/reports/general-ledger?account={{ $a->id }}&range=custom&from=2000-01-01&to={{ $biz->localToday()->toDateString() }}">@money($shown)</a></td>
                    <td>@include('partials.status', ['s' => $a->is_active ? 'active' : 'archived'])</td>
                </tr>
            @endforeach
        @endforeach
        </tbody></table>
    </div>
    @if ($ctx->can('journals.manage'))
    <div class="card"><div class="card-head"><h2>Add account</h2></div>
        <form method="post" action="{{ $bu }}/accounts" class="card-body grid gap-2">@csrf
            <div><label class="label" for="code">Code</label><input class="input" id="code" name="code" required></div>
            <div><label class="label" for="aname">Name</label><input class="input" id="aname" name="name" required></div>
            <div><label class="label" for="atype">Type</label><select class="input" id="atype" name="type">@foreach (\App\Models\ChartAccount::TYPES as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach</select></div>
            <div><label class="label" for="asub">Detail</label><select class="input" id="asub" name="subtype"><option value="">—</option>@foreach (['operating' => 'Operating', 'cogs' => 'Cost of goods sold', 'other' => 'Other income/expense', 'fixed_asset' => 'Fixed asset', 'current_asset' => 'Current asset', 'bank' => 'Bank account', 'credit_card' => 'Credit card', 'loan' => 'Loan', 'owner' => 'Owner equity', 'contra' => 'Contra'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
            <div><button class="btn btn-primary">Add</button></div>
            <p class="hint">Accounts with history can be archived but never deleted.</p>
        </form>
    </div>
    @endif
</div>
@endsection
