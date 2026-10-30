@extends('layouts.app')
@section('title', $contract ? 'Edit agreement' : 'New agreement')
@section('content')
@php
    $input = $version?->snapshot['input'] ?? [];
    $signers = old('signers', $version ? $version->recipients->where('role', 'client_signer')->map(fn ($r) => ['name' => $r->name, 'email' => $r->email, 'title' => $r->title])->values()->all() : []);
    if (count($signers) < 2) $signers = array_pad($signers, 2, []);
    $milestoneRows = old('milestones', $milestones->where('status', 'pending')->map(fn ($m) => ['name' => $m->name, 'amount' => number_format($m->amount_cents / 100, 2, '.', ''), 'due_date' => $m->due_date?->toDateString(), 'description' => $m->description])->values()->all());
    $milestoneRows = array_values(array_filter($milestoneRows, fn ($m) => ! empty($m['name'])));
    $counter = old('countersigner_user_id', $version?->recipients->firstWhere('role', 'countersigner')?->countersigner_user_id);
    $clientId = (int) old('client_id', $selectedClient);
    $signerContacts = function ($c) {
        if (! $c) return [];
        $list = $c->contacts->filter(fn ($ct) => $ct->email)
            ->sortBy(fn ($ct) => [$ct->is_signer ? 0 : 1, $ct->name])
            ->map(fn ($ct) => ['name' => $ct->name, 'email' => $ct->email, 'title' => $ct->title, 'signer' => (bool) $ct->is_signer])->values();
        if ($c->email && ! $list->contains(fn ($x) => strcasecmp($x['email'], $c->email) === 0)) {
            $list->push(['name' => $c->display_name, 'email' => $c->email, 'title' => '', 'signer' => false]);
        }

        return $list->all();
    };
    $currentSigners = $signerContacts($clients->firstWhere('id', $clientId) ?? ($contract ? $contract->client()->with('contacts')->first() : null));
    $clientBlocker = $clientBlocker ?? null;
    if (! $contract && ! old('signers') && ($currentSigners[0]['signer'] ?? false) && empty($signers[0]['email'])) $signers[0] = $currentSigners[0];
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
            <div><label class="label" for="effective_date">Effective date</label><input class="input" type="date" id="effective_date" name="effective_date" value="{{ old('effective_date', $contract?->effective_date?->toDateString()) }}"><p class="hint">When the agreement takes legal effect. Blank = the date it's fully signed.</p></div>
            <div><label class="label" for="payment_terms_days">Payment terms (days)</label><input class="input" type="number" min="0" id="payment_terms_days" name="payment_terms_days" value="{{ old('payment_terms_days', $input['payment_terms_days'] ?? $biz->default_terms_days) }}"></div>
            @php $openEnded = (bool) old('term_open_ended', $contract?->term_open_ended ?? false); @endphp
            <fieldset class="grid gap-4 rounded-lg border border-slate-200 p-4 sm:col-span-2 sm:grid-cols-2" data-term>
                <legend class="px-1 text-sm font-medium text-slate-700">Term</legend>
                <div><label class="label" for="term_start_date">Term begins</label><input class="input" type="date" id="term_start_date" name="term_start_date" value="{{ old('term_start_date', $contract?->term_start_date?->toDateString()) }}"><p class="hint">Blank = same as the effective date.</p></div>
                <div><label class="label" for="term_end_date">Term ends</label><input class="input" type="date" id="term_end_date" name="term_end_date" value="{{ $openEnded ? '' : old('term_end_date', $contract?->term_end_date?->toDateString()) }}" @disabled($openEnded) data-term-end>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="hidden" name="term_open_ended" value="0"><input class="check" type="checkbox" name="term_open_ended" value="1" @checked($openEnded) data-open-ended> Open-ended — continues until terminated</label></div>
            </fieldset>
            <div><label class="label" for="committed_value">Committed value ($)</label><input class="input" type="number" step="0.01" min="0" id="committed_value" name="committed_value" value="{{ old('committed_value', $contract ? number_format($contract->committed_value_cents / 100, 2, '.', '') : '') }}"></div>
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
            @if (! empty($input['recurring_fee']))<input type="hidden" name="recurring_fee" value="{{ $input['recurring_fee'] }}">@endif
            <div><label class="label" for="body">Agreement text</label>
                @include('partials.rich-editor', ['name' => 'body', 'id' => 'body', 'value' => $bodyHtml, 'fields' => $fields, 'label' => 'Agreement text'])
                <p class="hint">Use “Insert field” for values filled in from the client, business and fields above (e.g. <code>@{{client.legal_name}}</code>). Fields are replaced when you save; missing values block sending.</p></div>
        </div>
        @php $mode = old('billing_mode', $contract?->billing_mode ?? 'draft'); @endphp
        <div class="card" data-milestones>
            <div class="card-head"><div><h2>Billing plan</h2><p class="text-xs text-slate-500">Invoices are generated from this plan once the agreement is fully signed, and each one links back to it.</p></div></div>
            <div class="card-body space-y-6">
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Monthly fee</h3>
                    <div class="mt-2 grid gap-3 sm:grid-cols-3">
                        <div><label class="label" for="monthly_fee">Amount per month ($)</label><input class="input" type="number" step="0.01" min="0" id="monthly_fee" name="monthly_fee" value="{{ old('monthly_fee', $contract?->billing_monthly_cents ? number_format($contract->billing_monthly_cents / 100, 2, '.', '') : '') }}" placeholder="None"></div>
                        <div><label class="label" for="monthly_fee_description">Invoice line</label><input class="input" id="monthly_fee_description" name="monthly_fee_description" maxlength="200" value="{{ old('monthly_fee_description', $contract?->billing_monthly_description) }}" placeholder="Monthly retainer"></div>
                        <div><label class="label" for="billing_start_date">First invoice</label><input class="input" type="date" id="billing_start_date" name="billing_start_date" value="{{ old('billing_start_date', $contract?->billing_start_date?->toDateString()) }}"><p class="hint">Blank = term begin date. Repeats monthly until the term ends.</p></div>
                    </div>
                </section>
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Milestone payments</h3>
                    <div class="mt-2 overflow-x-auto" @if(! $milestoneRows) hidden @endif data-lines-wrap>
                        <table class="tbl" data-lines data-allow-empty>
                            <thead><tr><th class="min-w-[14rem]">Milestone</th><th class="w-36">Amount ($)</th><th class="w-44">Invoice date</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                            <tbody>
                            @foreach ($milestoneRows as $i => $m)
                                @include('contracts._milestone', ['i' => $i, 'm' => $m])
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-sm text-slate-500" @if($milestoneRows) hidden @endif data-lines-empty>No milestone payments.</p>
                    <template id="line-template">@include('contracts._milestone', ['i' => '__I__', 'm' => []])</template>
                    <button type="button" class="btn btn-sm mt-3" data-add-line>+ Add milestone</button>
                </section>
                <section class="rounded-lg bg-slate-50 p-4">
                    <label class="flex items-start gap-2 text-sm"><input type="hidden" name="billing_auto" value="0"><input class="check mt-0.5" type="checkbox" name="billing_auto" value="1" @checked(old('billing_auto', $contract?->billing_auto ?? true))>
                        <span><span class="font-medium text-slate-900">Create these invoices automatically when the agreement is fully signed</span>
                        <span class="block text-xs text-slate-500">One invoice per milestone (dated on its invoice date) and a monthly recurring schedule for the monthly fee.</span></span></label>
                    <div class="mt-3 max-w-sm"><label class="label" for="billing_mode">When an invoice is due</label>
                        <select class="input" id="billing_mode" name="billing_mode">@foreach (\App\Services\Contracts\ContractBillingService::MODES as $k => $v)<option value="{{ $k }}" @selected($mode === $k)>{{ $v }}</option>@endforeach</select></div>
                    <p class="hint mt-2">Available in the agreement text: <code>@{{agreement.monthly_fee}}</code>, <code>@{{agreement.billing_start_date}}</code>, <code>@{{agreement.milestones}}</code>, <code>@{{agreement.milestone_1_name}}</code>, <code>@{{agreement.milestone_1_amount}}</code>, <code>@{{agreement.milestone_1_date}}</code> … up to milestone 5.</p>
                </section>
            </div>
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
                        <option value="">{{ $currentSigners ? 'Pick a client contact…' : 'No contacts with an email on this client' }}</option>
                        @foreach (collect($currentSigners)->groupBy(fn ($sc) => $sc['signer'] ? 'Signers' : 'Other contacts', true) as $group => $items)
                            <optgroup label="{{ $group }}">
                            @foreach ($items as $k => $sc)<option value="{{ $k }}" data-name="{{ $sc['name'] }}" data-email="{{ $sc['email'] }}" data-title="{{ $sc['title'] }}" @selected(($s['email'] ?? null) === $sc['email'])>{{ $sc['name'] }} — {{ $sc['email'] }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <input class="input input-sm" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" placeholder="Full name" aria-label="Signer {{ $i + 1 }} name" data-signer-field="name">
                    <input class="input input-sm" type="email" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" placeholder="Email" aria-label="Signer {{ $i + 1 }} email" data-signer-field="email">
                    <input class="input input-sm" name="signers[{{ $i }}][title]" value="{{ $s['title'] ?? '' }}" placeholder="Title (optional)" aria-label="Signer {{ $i + 1 }} title" data-signer-field="title">
                </div>
            @endforeach
            <p class="hint">Pick from the client’s contacts (those marked “Can sign contracts” are listed first), or type anyone’s details.</p>
            <div><label class="label" for="countersigner_user_id">Business countersigner (optional)</label>
                <select class="input" id="countersigner_user_id" name="countersigner_user_id"><option value="">None</option>@foreach ($countersigners as $u)<option value="{{ $u->id }}" @selected($counter == $u->id)>{{ $u->label() }}</option>@endforeach</select>
                <input class="input input-sm mt-1" name="countersigner_title" placeholder="Countersigner title" aria-label="Countersigner title"></div>
        </div>
        <div class="card card-body"><button class="btn btn-primary w-full">Save draft</button><p class="hint mt-2">After saving you can preview, then lock &amp; send. Sent versions can never be edited.</p></div>
    </aside>
</form>
@endsection
