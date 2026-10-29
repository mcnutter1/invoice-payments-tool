@extends('layouts.app')
@section('title', 'Reconciliation')
@section('content')
<h1>Reconcile {{ $session->bankAccount->name }} @include('partials.status', ['s' => $session->status])</h1>
<p class="text-sm text-slate-500">Statement ending {{ $session->statement_date->format('M j, Y') }} · beginning @money($session->statement_opening_cents) · ending @money($session->statement_closing_cents)</p>
<form method="post" action="{{ $bu }}/reconciliations/{{ $session->id }}" data-reconcile data-opening="{{ $session->statement_opening_cents }}" data-closing="{{ $session->statement_closing_cents }}" data-sign="{{ $sign }}">
    @csrf
    <div class="card mt-4 overflow-x-auto">
        <table class="tbl"><thead><tr><th class="w-8">@if($session->status === 'in_progress')<input type="checkbox" class="check" data-check-all="lines[]" aria-label="Select all">@endif</th><th>Date</th><th>Entry</th><th class="num">Deposits / charges</th><th></th></tr></thead><tbody>
        @forelse ($lines as $l)
            <tr><td>@if($session->status === 'in_progress')<input type="checkbox" class="check" name="lines[]" value="{{ $l->id }}" data-amount="{{ $l->debit_cents - $l->credit_cents }}" @checked(in_array($l->id, $matchedIds)) aria-label="Cleared">@else ✓ @endif</td>
                <td>{{ $l->entry_date->format('M j, Y') }}</td><td>{{ $l->entry->memo }}</td><td class="num">@money($sign * ($l->debit_cents - $l->credit_cents))</td>
                <td>@if(in_array($l->id, $matchedIds))<span class="chip chip-green">Matched to statement row</span>@endif</td></tr>
        @empty
            <tr><td colspan="5" class="text-slate-500">No uncleared ledger activity on or before the statement date.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    @if ($session->status === 'in_progress')
    <div class="card card-body sticky bottom-2 mt-4 flex flex-wrap items-center gap-6 text-sm" aria-live="polite">
        <span>Cleared balance: <strong data-cleared>—</strong></span>
        <span>Statement ending: <strong>@money($session->statement_closing_cents)</strong></span>
        <span>Difference: <strong data-difference>—</strong></span>
        <button class="btn btn-primary ml-auto">Finish reconciliation</button>
    </div>
    @endif
</form>
@if ($session->status === 'in_progress')
    <form method="post" action="{{ $bu }}/reconciliations/{{ $session->id }}/cancel" class="mt-2">@csrf<button class="btn btn-ghost btn-sm">Cancel reconciliation</button></form>
@elseif ($session->status === 'completed' && $ctx->can('banking.manage'))
    <form method="post" action="{{ $bu }}/reconciliations/{{ $session->id }}/undo" class="mt-4 flex gap-2" data-confirm="Undo this reconciliation?">@csrf<input class="input w-64" name="reason" placeholder="Reason" required aria-label="Reason"><button class="btn btn-danger">Undo reconciliation</button></form>
@endif
@endsection
