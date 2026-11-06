@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Invoices</h1>
    @if ($ctx->can('invoices.manage'))<a href="{{ $bu }}/invoices/new" class="btn btn-primary">+ New invoice</a>@endif
</div>
@include('partials.tabs', ['label' => 'Invoice filters', 'class' => 'mt-4', 'tabs' => collect(['all' => 'All', 'draft' => 'Drafts', 'unsent' => 'Not sent', 'open' => 'Open', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Cancelled'])
    ->map(fn ($v, $k) => [$v, '?status='.$k, $status === $k])->values()->all()])
<form method="get" class="mt-3 flex flex-wrap items-end gap-2">
    <input type="hidden" name="status" value="{{ $status }}">
    <div><label for="q" class="label">Search</label><input id="q" name="q" class="input" placeholder="Number or client" value="{{ request('q') }}"></div>
    <div><label for="from" class="label">Issued from</label><input id="from" type="date" name="from" class="input" value="{{ request('from') }}"></div>
    <div><label for="to" class="label">to</label><input id="to" type="date" name="to" class="input" value="{{ request('to') }}"></div>
    @if ($status !== 'void')
        <label class="flex items-center gap-2 pb-2 text-sm"><input class="check" type="checkbox" name="show_cancelled" value="1" @checked($showCancelled) data-autosubmit> Show cancelled</label>
    @endif
    <button class="btn">Apply</button>
</form>
<form method="post" action="{{ $bu }}/invoices/bulk-send" id="bulk" data-confirm="Queue emails for the selected issued invoices? Each recipient gets a new secure link.">
    @csrf
    <div class="card mt-4 overflow-x-auto">
        <table class="tbl">
            <thead><tr>
                @if ($ctx->can('invoices.send'))<th class="w-8"><input type="checkbox" class="check" data-check-all="ids[]" aria-label="Select all"></th>@endif
                @php $s = fn($c) => '?'.http_build_query(array_merge(request()->query(), ['sort' => ($sort === $c ? '-'.$c : $c)])); @endphp
                <th><a href="{{ $s('number') }}">Number</a></th><th>Client</th><th><a href="{{ $s('issue_date') }}">Issued</a></th><th><a href="{{ $s('due_date') }}">Due</a></th>
                <th class="num"><a href="{{ $s('total_cents') }}">Total</a></th><th class="num"><a href="{{ $s('balance_cents') }}">Balance</a></th><th>Status</th><th>Client activity</th>
            </tr></thead>
            <tbody>
            @forelse ($invoices as $i)
                @php $cancelled = $i->status === 'void'; $eng = $engagement[$i->id] ?? null; @endphp
                <tr @class(['bg-slate-50 text-slate-400' => $cancelled])>
                    @if ($ctx->can('invoices.send'))<td>@if($i->isIssued())<input type="checkbox" class="check" name="ids[]" value="{{ $i->id }}" aria-label="Select {{ $i->number }}">@endif</td>@endif
                    <td><a href="{{ $bu }}/invoices/{{ $i->id }}" @class(['font-medium', 'text-slate-400 line-through' => $cancelled])>{{ $i->displayNumber() }}</a></td>
                    <td @class(['line-through' => $cancelled])>{{ $i->client->display_name }}</td>
                    <td>{{ $i->issue_date?->format('M j, Y') }}</td>
                    <td>{{ $i->due_date?->format('M j, Y') }}</td>
                    <td @class(['num', 'line-through' => $cancelled])>@money($i->total_cents)</td>
                    <td class="num">{{ $cancelled ? '—' : \App\Support\Money::format($i->balance_cents) }}</td>
                    <td>@include('partials.invoice-status', ['i' => $i])</td>
                    <td class="text-xs">
                        @if ($cancelled)
                        @elseif ($eng)
                            <span class="chip {{ $eng['score'] >= 4 ? 'chip-green' : ($eng['score'] === 3 ? 'chip-blue' : 'chip-gray') }}" title="{{ $eng['at']->timezone($biz->timezone)->format('M j, g:ia') }}">{{ $eng['label'] }}</span>
                        @elseif ($i->isIssued())
                            <span class="text-slate-500">{{ ['not_sent' => 'Not sent', 'queued' => 'Queued', 'sent' => 'Sent · not opened', 'failed' => 'Send failed', 'unknown' => 'Send unknown'][$i->delivery_status] ?? $i->delivery_status }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty m-4">No invoices match. @if($ctx->can('invoices.manage'))<a href="{{ $bu }}/invoices/new">Create an invoice</a>.@endif</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($ctx->can('invoices.send') && $invoices->count())
        <div class="mt-3 flex items-center gap-2">
            <label for="bulk-kind" class="text-sm">With selected:</label>
            <select id="bulk-kind" name="kind" class="input input-sm w-auto"><option value="invoice">Send invoice</option><option value="reminder">Send reminder</option></select>
            <button class="btn btn-sm">Review &amp; queue</button>
        </div>
    @endif
</form>
<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
