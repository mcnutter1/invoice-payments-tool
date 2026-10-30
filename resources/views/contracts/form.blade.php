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
    $clientId = (int) old('client_id', $selectedClient);
    $signerContacts = fn ($c) => $c ? $c->contacts->where('is_signer', true)->filter(fn ($ct) => $ct->email)->map(fn ($ct) => ['name' => $ct->name, 'email' => $ct->email, 'title' => $ct->title])->values()->all() : [];
    $currentSigners = $signerContacts($clients->firstWhere('id', $clientId) ?? ($contract ? $contract->client()->with('contacts')->first() : null));
    $clientBlocker = $clientBlocker ?? null;
    if (! $contract && ! old('signers') && $currentSigners && empty($signers[0]['email'])) $signers[0] = $currentSigners[0];
@endphp
<h1>{{ $contract ? 'Edit '.$contract->number.' (version '.$version->version.')' : 'New agreement' }}</h1>
<form method="post" action="{{ $contract ? $bu.'/contracts/'.$contract->id : $bu.'/contracts' }}" class="mt-4 grid gap-4 xl:grid-cols-3">
    @csrf
    <div class="space-y-4 xl:col-span-2">
        <div class="card card-body grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="client_id">Client</label>
                @if ($contract && $clientBlocker)
                    <div class="input bg-slate-50"><a href="{{ $bu }}/clients/{{ $contract->client_id }}">{{ $contract->client->display_name }}</a></div>
                    <p class="hint">Can’t be changed: {{ $clientBlocker }}</p>
                @else
                <select class="input" id="client_id" name="client_id" required data-signer-source @if($contract) data-confirm-change="Change the client for this agreement? Signers and merge fields will update when you save." @endif>
                    <option value="">Choose…</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}" data-contacts="{{ json_encode($signerContacts($c)) }}" @selected($clientId === $c->id)>{{ $c->display_name }}</option>@endforeach
                    @if ($contract && ! $clients->contains('id', $contract->client_id))<option value="{{ $contract->client_id }}" selected data-contacts="{{ json_encode($currentSigners) }}">{{ $contract->client->display_name }} (archived)</option>@endif
                </select>
                @if ($contract)<p class="hint"><a href="{{ $bu }}/clients/{{ $contract->client_id }}">View client profile</a> to add or edit signer contacts.</p>@endif
                @endif
            </div>
            <div><label class="label" for="title">Title</label><input class="input" id="title" name="title" required value="{{ old('title', $contract?->title ?? 'Service Agreement') }}"></div>
            <div><label class="label" for="effective_date">Effective date</label><input class="input" type="date" id="effective_date" name="effective_date" value="{{ old('effective_date', $contract?->effective_date?->toDateString()) }}"></div>
            <div><label class="label" for="term_end_date">Term ends</label><input class="input" type="date" id="term_end_date" name="term_end_date" value="{{ old('term_end_date', $contract?->term_end_date?->toDateString()) }}"></div>
            <div><label class="label" for="committed_value">Committed value ($)</label><input class="input" type="number" step="0.01" min="0" id="committed_value" name="committed_value" value="{{ old('committed_value', $contract ? number_format($contract->committed_value_cents / 100, 2, '.', '') : '') }}"></div>
            <div><label class="label" for="payment_terms_days">Payment terms (days)</label><input class="input" type="number" min="0" id="payment_terms_days" name="payment_terms_days" value="{{ old('payment_terms_days', $input['payment_terms_days'] ?? $biz->default_terms_days) }}"></div>
            <div><label class="label" for="value_cap">Value cap ($, optional)</label><input class="input" type="number" step="0.01" min="0" id="value_cap" name="value_cap" value="{{ old('value_cap', $contract?->value_cap_cents ? number_format($contract->value_cap_cents / 100, 2, '.', '') : '') }}"></div>
            <div><label class="label" for="cap_behavior">When invoicing exceeds the cap</label><select class="input" id="cap_behavior" name="cap_behavior"><option value="warn" @selected(($contract?->cap_behavior ?? 'warn') === 'warn')>Warn</option><option value="block" @selected($contract?->cap_behavior === 'block')>Block issuing</option></select></div>
        </div>
        <div class="card card-body space-y-4">
            @php
                $selectedTpl = old('template_id', $version?->template_id ?? (! $contract ? $templates->first()?->id : null));
                $bodyHtml = old('body', $version ? \App\Support\RichText::toHtml((string) $version->body) : \App\Support\RichText::toHtml((string) $templates->firstWhere('id', $selectedTpl)?->body));
            @endphp
            <div><label class="label" for="template_id">Start from template</label><select class="input" id="template_id" name="template_id" data-template-select>
                <option value="">Blank — write your own</option>
                @foreach ($templates as $t)<option value="{{ $t->id }}" data-body="{{ \App\Support\RichText::toHtml((string) $t->body) }}" @selected($selectedTpl == $t->id)>{{ $t->name }} (v{{ $t->version }})</option>@endforeach
            </select><p class="hint">Choosing a template loads its text below. Edit freely — changes apply to this agreement only, never to the template.</p></div>
            <div><label class="label" for="scope">Scope of services <code class="text-xs">@{{agreement.scope}}</code></label><textarea class="input" id="scope" name="scope" rows="4">{{ old('scope', $input['scope'] ?? '') }}</textarea></div>
            <div><label class="label" for="fees">Fees <code class="text-xs">@{{agreement.fees}}</code></label><textarea class="input" id="fees" name="fees" rows="3">{{ old('fees', $input['fees'] ?? '') }}</textarea></div>
            <div><label class="label" for="recurring_fee">Recurring fee <code class="text-xs">@{{agreement.recurring_fee}}</code></label><input class="input" id="recurring_fee" name="recurring_fee" value="{{ old('recurring_fee', $input['recurring_fee'] ?? '') }}"></div>
            <div><label class="label" for="body">Agreement text</label>
                @include('partials.rich-editor', ['name' => 'body', 'id' => 'body', 'value' => $bodyHtml, 'fields' => $fields, 'label' => 'Agreement text'])
                <p class="hint">Use “Insert field” for values filled in from the client, business and fields above (e.g. <code>@{{client.legal_name}}</code>). Fields are replaced when you save; missing values block sending.</p></div>
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
            <label class="flex items-start gap-2 text-sm"><input type="hidden" name="require_email_code" value="0"><input class="check mt-0.5" type="checkbox" name="require_email_code" value="1" @checked(old('require_email_code', $contract?->require_email_code ?? true))>
                <span>Require a one-time email code before signing<span class="block text-xs text-slate-500">Stronger evidence of who signed. When off, possession of the personal signing link is the verification.</span></span></label>
            @foreach ($signers as $i => $s)
                <div class="space-y-1 rounded border border-slate-200 p-2" data-signer-slot>
                    <div class="text-xs font-medium text-slate-500">Client signer {{ $i + 1 }}{{ $i > 0 ? ' (optional)' : '' }}</div>
                    <select class="input input-sm" data-signer-pick aria-label="Choose client signer {{ $i + 1 }} from the client's signer contacts">
                        <option value="">{{ $currentSigners ? 'Pick a signer contact…' : 'No signer contacts on this client' }}</option>
                        @foreach ($currentSigners as $k => $sc)<option value="{{ $k }}" data-name="{{ $sc['name'] }}" data-email="{{ $sc['email'] }}" data-title="{{ $sc['title'] }}" @selected(($s['email'] ?? null) === $sc['email'])>{{ $sc['name'] }} — {{ $sc['email'] }}</option>@endforeach
                    </select>
                    <input class="input input-sm" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" placeholder="Full name" aria-label="Signer {{ $i + 1 }} name" data-signer-field="name">
                    <input class="input input-sm" type="email" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" placeholder="Email" aria-label="Signer {{ $i + 1 }} email" data-signer-field="email">
                    <input class="input input-sm" name="signers[{{ $i }}][title]" value="{{ $s['title'] ?? '' }}" placeholder="Title (optional)" aria-label="Signer {{ $i + 1 }} title" data-signer-field="title">
                </div>
            @endforeach
            <p class="hint">Signer contacts are the client’s contacts marked “Can sign contracts”. You can also type anyone’s details.</p>
            <div><label class="label" for="countersigner_user_id">Business countersigner (optional)</label>
                <select class="input" id="countersigner_user_id" name="countersigner_user_id"><option value="">None</option>@foreach ($countersigners as $u)<option value="{{ $u->id }}" @selected($counter == $u->id)>{{ $u->label() }}</option>@endforeach</select>
                <input class="input input-sm mt-1" name="countersigner_title" placeholder="Countersigner title" aria-label="Countersigner title"></div>
        </div>
        <div class="card card-body"><button class="btn btn-primary w-full">Save draft</button><p class="hint mt-2">After saving you can preview, then lock &amp; send. Sent versions can never be edited.</p></div>
    </aside>
</form>
@endsection
