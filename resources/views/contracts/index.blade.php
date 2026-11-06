@extends('layouts.app')
@section('title', 'Contracts')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Contracts</h1>
    <div class="flex gap-2"><a href="{{ $bu }}/contract-templates" class="btn">Templates</a>@if ($ctx->can('contracts.manage'))<a href="{{ $bu }}/contracts/new" class="btn btn-primary">+ New contract</a>@endif</div>
</div>
@include('partials.tabs', ['label' => 'Contract filters', 'class' => 'mt-4', 'tabs' => collect(['' => 'All', 'draft' => 'Drafts', 'sent' => 'Sent', 'partially_signed' => 'Partially signed', 'completed' => 'Completed', 'declined' => 'Declined', 'voided' => 'Voided', 'expired' => 'Expired'])
    ->map(fn ($v, $k) => [$v, '?status='.$k, request('status', '') === $k])->values()->all()])
<div class="card mt-4 overflow-x-auto">
    <table class="tbl"><thead><tr><th>Number</th><th>Title</th><th>Client</th><th>Effective</th><th class="num">Committed</th><th>Status</th></tr></thead><tbody>
    @forelse ($contracts as $c)
        <tr><td><a href="{{ $bu }}/contracts/{{ $c->id }}">{{ $c->number }}</a></td><td>{{ $c->title }} @if($c->externally_signed)<span class="chip chip-gray">Externally signed</span>@endif</td><td>{{ $c->client->display_name }}</td>
            <td>{{ $c->effective_date?->format('M j, Y') }}</td><td class="num">@money($c->committed_value_cents)</td><td>@include('partials.status', ['s' => $c->status])</td></tr>
    @empty
        <tr><td colspan="6"><div class="empty m-4">No contracts yet.</div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $contracts->links() }}</div>
@endsection
