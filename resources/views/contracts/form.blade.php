@extends('layouts.app')
@section('title', $contract ? 'Edit agreement' : 'New agreement')
@section('content')
@php
    $input = $version?->snapshot['input'] ?? [];
    $signers = old('signers', $version ? $version->recipients->where('role', 'client_signer')->map(fn ($r) => ['name' => $r->name, 'email' => $r->email, 'title' => $r->title])->values()->all() : []);
    if (count($signers) < 2) $signers = array_pad($signers, 2, []);
    $milestoneRows = old('milestones', $milestones->where('status', 'pending')->map(fn ($m) => ['name' => $m->name, 'amount' => number_format($m->amount_cents / 100, 2, '.', ''), 'due_date' => $m->due_date?->toDateString()])->values()->all());
    $milestoneRows = array_pad($milestoneRows, max(3, count($milestoneRows) + 1), []);
    $counter = old('countersigner_user_id', $version?->recipients->firstWhere('role', 'countersigner')?->countersigner_user_id);
@endphp
<h1>{{ $contract ? 'Edit '.$contract->number.' (version '.$version->version.')' : 'New agreement' }}</h1>
<form method="post" action="{{ $contract ? $bu.'/contracts/'.$contract->id : $bu.'/contracts' }}" class="mt-4 grid gap-4 xl:grid-cols-3">
    @csrf
    <div class="space-y-4 xl:col-span-2">
        <div class="card card-body grid gap-4 sm:grid-cols-2">
            @unless ($contract)
            <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id" required data-signer-source>
                <option value="">Choose…</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}" data-signer-name="{{ $c->contacts->firstWhere('is_signer', true)?->name }}" data-signer-email="{{ $c->contacts->firstWhere('is_signer', true)?->email ?? $c->email }}" @selected(old('client_id', $selectedClient) == $c->id)>{{ $c->display_name }}</option>@endforeach
            </select></div>
            @endunless
            <div class="{{ $contract ? 'sm:col-span-2' : '' }}"><label class="label" for="title">Title</label><input class="input" id="title" name="title" required value="{{ old('title', $contract?->title ?? 'Service Agreement') }}"></div>
            <div><label class="label" for="effective_date">Effective date</label><input class="input" type="date" id="effective_date" name="effective_date" value="{{ old('effective_date', $contract?->effective_date?->toDateString()) }}"></div>
            <div><label class="label" for="term_end_date">Term ends</label><input class="input" type="date" id="term_end_date" name="term_end_date" value="{{ old('term_end_date', $contract?->term_end_date?->toDateString()) }}"></div>
            <div><label class="label" for="committed_value">Committed value ($)</label><input class="input" type="number" step="0.01" min="0" id="committed_value" name="committed_value" value="{{ old('committed_value', $contract ? number_format($contract->committed_value_cents / 100, 2, '.', '') : '') }}"></div>
            <div><label class="label" for="payment_terms_days">Payment terms (days)</label><input class="input" type="number" min="0" id="payment_terms_days" name="payment_terms_days" value="{{ old('payment_terms_days', $input['payment_terms_days'] ?? $biz->default_terms_days) }}"></div>
            <div><label class="label" for="value_cap">Value cap ($, optional)</label><input class="input" type="number" step="0.01" min="0" id="value_cap" name="value_cap" value="{{ old('value_cap', $contract?->value_cap_cents ? number_format($contract->value_cap_cents / 100, 2, '.', '') : '') }}"></div>
            <div><label class="label" for="cap_behavior">When invoicing exceeds the cap</label><select class="input" id="cap_behavior" name="cap_behavior"><option value="warn" @selected(($contract?->cap_behavior ?? 'warn') === 'warn')>Warn</option><option value="block" @selected($contract?->cap_behavior === 'block')>Block issuing</option></select></div>
        </div>
        <div class="card card-body space-y-4">
            <div><label class="label" for="template_id">Template</label><select class="input" id="template_id" name="template_id">
                <option value="">Use the text below</option>
                @foreach ($templates as $t)<option value="{{ $t->id }}" @selected(old('template_id', $version?->template_id ?? (! $contract ? $templates->first()?->id : null)) == $t->id)>{{ $t->name }} (v{{ $t->version }})</option>@endforeach
            </select><p class="hint">Choosing a template replaces the agreement text when saved. Leave blank to keep your edited text.</p></div>
            <div><label class="label" for="scope">Scope of services <code class="text-xs">@{{agreement.scope}}</code></label><textarea class="input" id="scope" name="scope" rows="4">{{ old('scope', $input['scope'] ?? '') }}</textarea></div>
            <div><label class="label" for="fees">Fees <code class="text-xs">@{{agreement.fees}}</code></label><textarea class="input" id="fees" name="fees" rows="3">{{ old('fees', $input['fees'] ?? '') }}</textarea></div>
            <div><label class="label" for="recurring_fee">Recurring fee <code class="text-xs">@{{agreement.recurring_fee}}</code></label><input class="input" id="recurring_fee" name="recurring_fee" value="{{ old('recurring_fee', $input['recurring_fee'] ?? '') }}"></div>
            @if ($version && ! $version->template_id)
                <div><label class="label" for="body">Agreement text</label><textarea class="input font-mono text-xs" id="body" name="body" rows="16">{{ old('body', $version->body) }}</textarea>
                    <p class="hint">Supports # headings, **bold** and - lists. Merge fields: {{ implode(', ', array_map(fn ($f) => str_repeat('{', 2).$f.str_repeat('}', 2), $fields)) }}</p></div>
            @endif
        </div>
        <div class="card overflow-x-auto"><div class="card-head"><h2>Billing milestones (optional)</h2></div>
            <table class="tbl"><thead><tr><th>Milestone</th><th class="w-40">Amount ($)</th><th class="w-44">Due</th></tr></thead><tbody>
            @foreach ($milestoneRows as $i => $m)
                <tr><td><input class="input input-sm" name="milestones[{{ $i }}][name]" value="{{ $m['name'] ?? '' }}" aria-label="Milestone name"></td>
                    <td><input class="input input-sm" type="number" step="0.01" min="0" name="milestones[{{ $i }}][amount]" value="{{ $m['amount'] ?? '' }}" aria-label="Milestone amount"></td>
                    <td><input class="input input-sm" type="date" name="milestones[{{ $i }}][due_date]" value="{{ $m['due_date'] ?? '' }}" aria-label="Milestone due date"></td></tr>
            @endforeach
            </tbody></table>
        </div>
    </div>
    <aside class="space-y-4">
        <div class="card card-body space-y-3">
            <h2>Signers</h2>
            <fieldset><legend class="label">Signing order</legend>
                <label class="mr-3 inline-flex items-center gap-1 text-sm"><input type="radio" name="signing_order" value="parallel" @checked(old('signing_order', $contract?->signing_order ?? 'parallel') === 'parallel')> Any order</label>
                <label class="inline-flex items-center gap-1 text-sm"><input type="radio" name="signing_order" value="sequential" @checked(old('signing_order', $contract?->signing_order) === 'sequential')> In order listed</label>
            </fieldset>
            @foreach ($signers as $i => $s)
                <div class="space-y-1 rounded border border-slate-200 p-2">
                    <div class="text-xs font-medium text-slate-500">Client signer {{ $i + 1 }}{{ $i > 0 ? ' (optional)' : '' }}</div>
                    <input class="input input-sm" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" placeholder="Full name" aria-label="Signer {{ $i + 1 }} name" @if($i === 0) data-signer-name-target @endif>
                    <input class="input input-sm" type="email" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" placeholder="Email" aria-label="Signer {{ $i + 1 }} email" @if($i === 0) data-signer-email-target @endif>
                    <input class="input input-sm" name="signers[{{ $i }}][title]" value="{{ $s['title'] ?? '' }}" placeholder="Title (optional)" aria-label="Signer {{ $i + 1 }} title">
                </div>
            @endforeach
            <div><label class="label" for="countersigner_user_id">Business countersigner (optional)</label>
                <select class="input" id="countersigner_user_id" name="countersigner_user_id"><option value="">None</option>@foreach ($countersigners as $u)<option value="{{ $u->id }}" @selected($counter == $u->id)>{{ $u->label() }}</option>@endforeach</select>
                <input class="input input-sm mt-1" name="countersigner_title" placeholder="Countersigner title" aria-label="Countersigner title"></div>
        </div>
        <div class="card card-body"><button class="btn btn-primary w-full">Save draft</button><p class="hint mt-2">After saving you can preview, then lock &amp; send. Sent versions can never be edited.</p></div>
    </aside>
</form>
@endsection
