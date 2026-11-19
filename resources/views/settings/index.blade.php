@extends('layouts.app')
@section('title', 'Settings')
@section('content')
@php $dis = $canManage ? '' : 'disabled'; @endphp
@include('settings._tabs')
<nav class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm" aria-label="On this page">
    <span class="text-xs font-medium uppercase tracking-wide text-slate-400">On this page</span>
    <a href="#profile">Profile</a><a href="#reminders">Reminders</a><a href="#w9">W-9</a><a href="#branding">Branding</a><a href="#payments">Payment methods</a><a href="#stripe">Stripe</a><a href="#venmo-feed">Venmo feed</a><a href="#email">Email</a>
</nav>

<form id="profile" method="post" action="{{ $bu }}/settings/profile" class="card mt-4">@csrf
    <div class="card-head"><h2>Business profile</h2></div>
    <fieldset class="card-body grid gap-4 sm:grid-cols-3" {{ $dis }}>
        <div><label class="label" for="legal_name">Legal name</label><input class="input" id="legal_name" name="legal_name" value="{{ old('legal_name', $b->legal_name) }}" required></div>
        <div><label class="label" for="display_name">Display name / DBA</label><input class="input" id="display_name" name="display_name" value="{{ old('display_name', $b->display_name) }}" required></div>
        <div><label class="label" for="entity_type">Entity type</label><input class="input" id="entity_type" name="entity_type" value="{{ old('entity_type', $b->entity_type) }}"></div>
        <div><label class="label" for="address_line1">Address</label><input class="input" id="address_line1" name="address_line1" value="{{ old('address_line1', $b->address_line1) }}"><input class="input mt-1" name="address_line2" value="{{ old('address_line2', $b->address_line2) }}" aria-label="Address line 2"></div>
        <div class="grid grid-cols-3 gap-2 self-end"><input class="input" name="city" placeholder="City" aria-label="City" value="{{ old('city', $b->city) }}"><input class="input" name="state" placeholder="State" aria-label="State" value="{{ old('state', $b->state) }}"><input class="input" name="postal_code" placeholder="ZIP" aria-label="ZIP" value="{{ old('postal_code', $b->postal_code) }}"></div>
        <div><label class="label" for="phone">Phone</label><input class="input" id="phone" name="phone" value="{{ old('phone', $b->phone) }}"></div>
        <div><label class="label" for="public_email">Public email</label><input class="input" id="public_email" name="public_email" type="email" value="{{ old('public_email', $b->public_email) }}"></div>
        <div><label class="label" for="website">Website</label><input class="input" id="website" name="website" type="url" value="{{ old('website', $b->website) }}"></div>
        <div><label class="label" for="timezone">Time zone</label><input class="input" id="timezone" name="timezone" value="{{ old('timezone', $b->timezone) }}" required></div>
        <div><label class="label" for="fiscal_year_start_month">Fiscal year starts</label><select class="input" id="fiscal_year_start_month" name="fiscal_year_start_month">@for ($m = 1; $m <= 12; $m++)<option value="{{ $m }}" @selected($b->fiscal_year_start_month == $m)>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>@endfor</select></div>
        <div><label class="label" for="reporting_basis">Default reporting basis</label><select class="input" id="reporting_basis" name="reporting_basis"><option value="accrual" @selected($b->reporting_basis === 'accrual')>Accrual</option><option value="cash" @selected($b->reporting_basis === 'cash')>Cash</option></select></div>
        <div><label class="label" for="invoice_prefix">Invoice prefix</label><input class="input" id="invoice_prefix" name="invoice_prefix" value="{{ old('invoice_prefix', $b->invoice_prefix) }}"></div>
        <div><label class="label" for="contract_prefix">Contract prefix</label><input class="input" id="contract_prefix" name="contract_prefix" value="{{ old('contract_prefix', $b->contract_prefix) }}"></div>
        <div><label class="label" for="default_terms_days">Default terms (days)</label><input class="input" id="default_terms_days" name="default_terms_days" type="number" min="0" value="{{ old('default_terms_days', $b->default_terms_days) }}"></div>
        <div><label class="label" for="tax_id">Tax ID (EIN)</label><input class="input" id="tax_id" name="tax_id" placeholder="{{ $taxId ?? 'Not set' }}" autocomplete="off"><p class="hint">Stored encrypted; shown masked.</p></div>
        <label class="flex items-center gap-2 self-center text-sm"><input type="hidden" name="show_tax_id" value="0"><input class="check" type="checkbox" name="show_tax_id" value="1" @checked($b->show_tax_id)> Show tax ID on invoices</label>
        <div><label class="label" for="invoice_link_ttl_days">Invoice link lifetime (days)</label><input class="input" id="invoice_link_ttl_days" name="invoice_link_ttl_days" type="number" min="1" max="365" value="{{ $b->invoice_link_ttl_days }}"></div>
        <div><label class="label" for="contract_link_ttl_days">Signing link lifetime (days)</label><input class="input" id="contract_link_ttl_days" name="contract_link_ttl_days" type="number" min="1" max="90" value="{{ $b->contract_link_ttl_days }}"></div>
        <div><label class="label" for="claim_review_days">Pause reminders after a payment report (days)</label><input class="input" id="claim_review_days" name="claim_review_days" type="number" min="1" max="30" value="{{ $b->claim_review_days }}"></div>
        <div class="sm:col-span-3 grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="default_customer_note">Default note to clients</label><textarea class="input" id="default_customer_note" name="default_customer_note" rows="2">{{ old('default_customer_note', $b->default_customer_note) }}</textarea></div>
            <div><label class="label" for="default_terms_text">Standard invoice terms</label><textarea class="input" id="default_terms_text" name="default_terms_text" rows="10" placeholder="{{ \App\Support\InvoiceTerms::STANDARD }}">{{ old('default_terms_text', $b->default_terms_text) }}</textarea><p class="hint">Blank uses the standard terms shown. Placeholders <code>{payment_terms}</code> (from the client’s or your default payment terms), <code>{business_name}</code> and <code>{client_name}</code> are filled in per invoice. Start a paragraph with “Heading:” to bold it. Client-specific terms are added after these.</p></div>
        </div>
        <div><button class="btn btn-primary">Save profile</button></div>
    </fieldset>
</form>

<form id="reminders" method="post" action="{{ $bu }}/settings/reminders" class="card mt-4">@csrf
    <div class="card-head"><h2>Automatic client reminders</h2></div>
    <fieldset class="card-body space-y-3" {{ $dis }}>
        @foreach (\App\Models\Business::REMINDERS as $k => $label)
            @php $r = $b->reminder($k); @endphp
            <div class="grid items-center gap-2 text-sm sm:grid-cols-3">
                <label class="flex items-center gap-2 sm:col-span-2"><input type="hidden" name="{{ $k }}[enabled]" value="0"><input class="check" type="checkbox" name="{{ $k }}[enabled]" value="1" @checked($r['enabled'])> {{ $label }}</label>
                <label class="flex items-center gap-2">every <input class="input input-sm w-20" name="{{ $k }}[every_days]" type="number" min="1" max="90" value="{{ $r['every_days'] }}" aria-label="{{ $label }}: days between reminders"> days</label>
            </div>
        @endforeach
        <p class="hint">Agreements: each signer is reminded until they sign or the link expires. Unviewed invoices: until the client opens the invoice page or PDF (email-open pixels don’t count, since mail scanners trigger them). Past-due invoices: counted from the due date; the email asks the client to report payment or a mailed check. Reminders pause after a payment report (see Profile). Runs daily; every reminder is recorded in the audit log.</p>
        <div><button class="btn btn-primary">Save reminders</button></div>
    </fieldset>
</form>

<form id="w9" method="post" action="{{ $bu }}/settings/w9" class="card mt-4 scroll-mt-4">@csrf
    <div class="card-head"><h2>Form W-9 (your taxpayer information)</h2>
        <span class="flex items-center gap-2">
            <span class="chip {{ $w9Missing ? 'chip-amber' : 'chip-green' }}">{{ $w9Missing ? 'Incomplete' : 'Ready to send' }}</span>
            @if ($canManage)<a class="btn btn-sm" href="{{ $bu }}/settings/w9/preview" target="_blank" rel="noopener">Open preview</a>@endif
        </span></div>
    <fieldset class="card-body grid gap-4 sm:grid-cols-2" {{ $dis }}>
        @if ($w9Missing)<div class="alert alert-warn sm:col-span-2">Still needed: {{ implode(', ', $w9Missing) }}.</div>@endif
        <p class="hint sm:col-span-2">These details fill in IRS Form W-9 (Rev. March 2024) automatically whenever you send it to a client or a client requests it from an invoice. Defaults come from your business profile.</p>
        <div><label class="label" for="w9-name">1 · Name of entity/individual</label><input class="input" id="w9-name" name="name" value="{{ old('name', $w9['name']) }}" required><p class="hint">For a sole proprietor or disregarded entity, the owner’s name.</p></div>
        <div><label class="label" for="w9-bn">2 · Business name / disregarded entity (if different)</label><input class="input" id="w9-bn" name="business_name" value="{{ old('business_name', $w9['business_name']) }}">
            @error('business_name')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div>
        <div class="sm:col-span-2"><label class="label" for="w9-class">3a · What kind of business is this for federal tax purposes?</label>
            <select class="input" id="w9-class" name="classification" required data-show-source><option value="">Choose…</option>
                @foreach (\App\Services\Tax\W9Service::CLASSIFICATIONS as $k => $v)<option value="{{ $k }}" @selected(old('classification', $w9['classification']) === $k)>{{ $v }}</option>@endforeach
            </select>
            <p class="hint">Not sure? A single-owner LLC that never filed an election with the IRS is a <em>disregarded entity</em>. A multi-member LLC is a partnership unless it elected to be taxed as a corporation.</p></div>
        <div class="alert alert-info sm:col-span-2" data-show-for="disregarded">For a single-member LLC, line 1 is <strong>the owner’s name</strong> and line 2 is <strong>the LLC’s name</strong>. The form checks “Individual/sole proprietor”, and the TIN is the owner’s SSN, or the LLC’s EIN if you prefer to use it. If the LLC is owned by a corporation, choose the owner’s classification instead.</div>
        <div data-show-for="llc"><label class="label" for="w9-llc">How is the LLC taxed?</label><select class="input" id="w9-llc" name="llc_code"><option value="">Choose…</option>@foreach (['C' => 'C — C corporation (filed Form 8832)', 'S' => 'S — S corporation (filed Form 2553)', 'P' => 'P — Partnership (multi-member, default)'] as $k => $v)<option value="{{ $k }}" @selected(old('llc_code', $w9['llc_code']) === $k)>{{ $v }}</option>@endforeach</select>
            @error('llc_code')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div>
        <div data-show-for="other"><label class="label" for="w9-other">Describe the classification</label><input class="input" id="w9-other" name="other_text" value="{{ old('other_text', $w9['other_text']) }}" placeholder="e.g. Nonprofit corporation">
            @error('other_text')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror</div>
        <div data-show-for="partnership trust llc" class="sm:col-span-2"><label class="flex items-start gap-2 text-sm"><input type="hidden" name="foreign_partners" value="0"><input class="check mt-0.5" type="checkbox" name="foreign_partners" value="1" @checked($w9['foreign_partners'])> 3b · We have foreign partners, owners or beneficiaries (only matters when giving this form to a partnership, trust or estate you have an ownership interest in)</label></div>
        <div data-show-for="c_corp s_corp partnership trust llc other" class="sm:col-span-2"><div class="grid grid-cols-2 gap-2">
            <div><label class="label" for="w9-exempt">4 · Exempt payee code</label><input class="input" id="w9-exempt" name="exempt_payee_code" value="{{ old('exempt_payee_code', $w9['exempt_payee_code']) }}" placeholder="Usually blank"><p class="hint">Corporations commonly use 5 only for specific payment types; most businesses leave this blank.</p></div>
            <div><label class="label" for="w9-fatca">FATCA exemption code</label><input class="input" id="w9-fatca" name="fatca_code" value="{{ old('fatca_code', $w9['fatca_code']) }}" placeholder="Usually blank"><p class="hint">Only for accounts held outside the U.S.</p></div>
        </div></div>
        <div><label class="label" for="w9-acct">7 · Account number(s) (optional)</label><input class="input" id="w9-acct" name="account_numbers" value="{{ old('account_numbers', $w9['account_numbers']) }}"></div>
        <div><label class="label" for="w9-addr">5 · Address (number, street, suite)</label><input class="input" id="w9-addr" name="address" value="{{ old('address', $w9['address']) }}" required></div>
        <div><label class="label" for="w9-csz">6 · City, state, and ZIP code</label><input class="input" id="w9-csz" name="city_state_zip" value="{{ old('city_state_zip', $w9['city_state_zip']) }}" required></div>
        <div><label class="label" for="w9-tintype">Part I · Taxpayer ID type</label>
            <select class="input" id="w9-tintype" name="tin_type"><option value="ein" @selected($w9['tin_type'] === 'ein')>Employer identification number (EIN)</option><option value="ssn" @selected($w9['tin_type'] === 'ssn')>Social security number (SSN)</option></select></div>
        <div><label class="label" for="w9-tin">Taxpayer ID number</label><input class="input" id="w9-tin" name="tin" autocomplete="off" inputmode="numeric" placeholder="{{ $w9Tin ?? 'Not set — 9 digits' }}">
            @error('tin')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            <p class="hint">Stored encrypted; leave blank to keep the current number. Uses the EIN from your profile if none is entered here.</p></div>
        <div><label class="label" for="w9-signer">Part II · Signer name</label><input class="input" id="w9-signer" name="signer_name" value="{{ old('signer_name', $w9['signer_name']) }}" required></div>
        <div><label class="label" for="w9-sig">Signature</label>
            <select class="input" id="w9-sig" name="signer_membership_id"><option value="">Typed signature (/s/ name)</option>
                @foreach ($signers as $m)<option value="{{ $m->id }}" @selected((int) $w9['signer_membership_id'] === $m->id) @disabled(! $m->signature_document_id)>{{ $m->user?->label() }}{{ $m->signature_document_id ? '’s saved signature' : ' (no saved signature)' }}</option>@endforeach
            </select><p class="hint">Members save a signature under “My signature”.</p></div>
        <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="hidden" name="backup_withholding" value="0"><input class="check mt-0.5" type="checkbox" name="backup_withholding" value="1" @checked($w9['backup_withholding'])> The IRS has notified us that we are currently subject to backup withholding (crosses out certification item 2)</label>
        <div class="grid gap-2 sm:col-span-2 sm:grid-cols-3">
            <div><label class="label" for="w9-ttl">Link expires after (days)</label><input class="input" id="w9-ttl" name="link_ttl_days" type="number" min="1" max="60" value="{{ old('link_ttl_days', $w9['link_ttl_days']) }}"></div>
            <div><label class="label" for="w9-max">Downloads allowed per link</label><input class="input" id="w9-max" name="max_downloads" type="number" min="1" max="50" value="{{ old('max_downloads', $w9['max_downloads']) }}"></div>
            <label class="flex items-center gap-2 self-end text-sm"><input type="hidden" name="allow_client_requests" value="0"><input class="check" type="checkbox" name="allow_client_requests" value="1" @checked($w9['allow_client_requests'])> Let clients request it from invoices</label>
        </div>
        <div><button class="btn btn-primary">Save W-9 details</button></div>
    </fieldset>
    @if ($canManage)
    <details class="border-t border-slate-100" @if(session('status') && str_contains((string) session('status'), 'W-9')) open @endif>
        <summary class="card-head cursor-pointer list-none"><h3>Preview</h3><span class="text-xs text-slate-500">Shows the saved details{{ $w9Missing ? ' — marked PREVIEW until complete' : '' }}</span></summary>
        <div class="card-body pt-0"><iframe src="{{ $bu }}/settings/w9/preview" title="W-9 preview" loading="lazy" class="h-[52rem] w-full rounded border border-slate-200 bg-white"></iframe></div>
    </details>
    @endif
</form>

<form id="branding" method="post" action="{{ $bu }}/settings/branding" enctype="multipart/form-data" class="card mt-4">@csrf
    <div class="card-head"><h2>Invoice branding</h2>@if($canManage)<a class="btn btn-sm" href="{{ $bu }}/settings/preview-pdf" target="_blank" rel="noopener">Preview PDF</a>@endif</div>
    <fieldset class="card-body grid gap-4 sm:grid-cols-3" {{ $dis }}>
        <div><label class="label" for="template">Template</label><select class="input" id="template" name="template"><option value="classic" @selected($b->brand('template') === 'classic')>Classic</option><option value="modern" @selected($b->brand('template') === 'modern')>Modern</option></select></div>
        <div><label class="label" for="accent">Accent color</label><input class="input h-10" id="accent" name="accent" type="color" value="{{ $b->brand('accent') }}"></div>
        <div><label class="label" for="font">Font</label><select class="input" id="font" name="font">@foreach (\App\Services\Documents\PdfRenderer::FONTS as $f)<option @selected($b->brand('font') === $f)>{{ $f }}</option>@endforeach</select></div>
        <div><label class="label" for="logo">Logo (PNG or JPEG)</label><input class="input" id="logo" name="logo" type="file" accept="image/png,image/jpeg">@if($b->logo_document_id)<label class="mt-1 flex items-center gap-2 text-xs"><input class="check" type="checkbox" name="remove_logo" value="1"> Remove current logo</label>@endif</div>
        <div><label class="label" for="header_text">Header text</label><input class="input" id="header_text" name="header_text" value="{{ $b->brand('header_text') }}"></div>
        <div><label class="label" for="footer_text">Footer text</label><input class="input" id="footer_text" name="footer_text" value="{{ $b->brand('footer_text') }}"></div>
        <fieldset class="space-y-1 text-sm"><legend class="label">Columns &amp; layout</legend>
            <label class="flex items-center gap-2"><input type="hidden" name="show_quantity" value="0"><input class="check" type="checkbox" name="show_quantity" value="1" @checked($b->brand('show_quantity'))> Quantity &amp; rate columns</label>
            <label class="flex items-center gap-2"><input type="hidden" name="show_sku" value="0"><input class="check" type="checkbox" name="show_sku" value="1" @checked($b->brand('show_sku'))> SKU column</label>
            <label class="flex items-center gap-2"><input type="hidden" name="due_date_prominent" value="0"><input class="check" type="checkbox" name="due_date_prominent" value="1" @checked($b->brand('due_date_prominent'))> Prominent amount due &amp; due date</label>
        </fieldset>
        <div class="sm:col-span-2"><label class="label" for="email_intro">Email introduction</label><textarea class="input" id="email_intro" name="email_intro" rows="2">{{ $b->brand('email_intro') }}</textarea></div>
        <div><button class="btn btn-primary">Save branding</button></div>
    </fieldset>
</form>

<form id="payments" method="post" action="{{ $bu }}/settings/payments" enctype="multipart/form-data" class="card mt-4 scroll-mt-4">@csrf
    @php
        $on = fn ($m) => in_array($m, old('payment_methods', $b->allowedMethods()), true);
        $ready = \App\Support\PaymentMethods::configured($b);
    @endphp
    <div class="card-head"><h2>Payment methods &amp; instructions</h2>@if ($b->payment_instructions_changed_at)<span class="text-xs text-slate-500">Last changed {{ $b->payment_instructions_changed_at->timezone($b->timezone)->format('M j, Y g:ia') }}</span>@endif</div>
    <fieldset class="card-body space-y-4" {{ $dis }}>
        @if ($pendingSends)<div class="alert alert-warn">{{ $pendingSends }} email(s) are queued right now. Invoices always show the instructions they were issued with.</div>@endif
        <p class="hint">Turn on the methods you accept. Each client can be limited to a subset (client profile), and each invoice can narrow it further. Card payments are verified automatically; every other method is confirmed by you once the money arrives — clients can tell you they’ve paid from the invoice page.</p>

        {{-- Card (Stripe) --}}
        <section class="rounded-lg border border-slate-200">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <label class="flex items-center gap-2 font-medium"><input class="check" type="checkbox" name="payment_methods[]" value="card" @checked($on('card')) @disabled(! $b->stripe_secret_key_encrypted)> Credit / debit card <span class="text-xs font-normal text-slate-500">via Stripe</span></label>
                @if ($b->stripe_secret_key_encrypted)<span class="chip chip-green">Stripe connected{{ $b->stripe_live ? '' : ' · test mode' }}</span>@else<a class="chip chip-amber" href="#stripe">Connect Stripe first</a>@endif
            </header>
            <p class="px-4 py-3 text-sm text-slate-600">Clients pay on a secure Stripe page; the payment is recorded, applied and receipted automatically. Stripe’s fee is booked to <em>Bank &amp; Merchant Fees</em> and the net to your payout account. Recurring schedules can offer card autopay.</p>
        </section>

        {{-- ACH / wire --}}
        <section class="rounded-lg border border-slate-200" data-bank-lookup data-lookup-url="{{ $bu }}/settings/bank-lookup">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <label class="flex items-center gap-2 font-medium"><input class="check" type="checkbox" name="payment_methods[]" value="ach" @checked($on('ach'))> ACH / wire transfer</label>
                <span class="chip {{ in_array('ach', $ready, true) ? 'chip-green' : 'chip-gray' }}">{{ in_array('ach', $ready, true) ? 'Details on file' : 'Not set up' }}</span>
            </header>
            <div class="grid gap-4 px-4 py-3 sm:grid-cols-3">
                <div><label class="label" for="ach_routing_number">ACH routing number</label>
                    <div class="flex gap-2"><input class="input font-mono" id="ach_routing_number" name="ach_routing_number" inputmode="numeric" maxlength="11" autocomplete="off" value="{{ old('ach_routing_number', $b->ach_routing_number) }}" data-lookup-routing><button type="button" class="btn btn-sm" data-lookup-btn="routing">Look up</button></div>
                    @error('ach_routing_number')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="ach_account_number">Account number</label>
                    <input class="input font-mono" id="ach_account_number" name="ach_account_number" inputmode="numeric" autocomplete="off" placeholder="{{ $achLast4 ? '•••• '.$achLast4.' (leave blank to keep)' : '4–17 digits' }}">
                    @error('ach_account_number')<p class="field-error">{{ $message }}</p>@enderror
                    <p class="hint">Stored encrypted. Shown in full only on invoices that offer ACH.</p></div>
                <div><label class="label" for="ach_account_type">Account type</label><select class="input" id="ach_account_type" name="ach_account_type"><option value="checking" @selected(old('ach_account_type', $b->ach_account_type) !== 'savings')>Checking</option><option value="savings" @selected(old('ach_account_type', $b->ach_account_type) === 'savings')>Savings</option></select></div>
                <div><label class="label" for="ach_bank_name">Bank name</label><input class="input" id="ach_bank_name" name="ach_bank_name" value="{{ old('ach_bank_name', $b->ach_bank_name) }}" placeholder="Filled in from the routing number" data-lookup-name>
                    <p class="hint" data-lookup-status>{{ $b->ach_bank_location ? 'Directory match · '.$b->ach_bank_location : 'Looked up from the Federal Reserve routing directory.' }}</p></div>
                <div><label class="label" for="ach_account_name">Account holder (beneficiary)</label><input class="input" id="ach_account_name" name="ach_account_name" value="{{ old('ach_account_name', $b->ach_account_name) }}" placeholder="{{ $b->legal_name }}"></div>
                <div><label class="label" for="ach_wire_routing_number">Wire routing number <span class="font-normal text-slate-400">(if different)</span></label><input class="input font-mono" id="ach_wire_routing_number" name="ach_wire_routing_number" inputmode="numeric" maxlength="11" autocomplete="off" value="{{ old('ach_wire_routing_number', $b->ach_wire_routing_number) }}">
                    @error('ach_wire_routing_number')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="ach_swift_code">SWIFT / BIC <span class="font-normal text-slate-400">(international wires)</span></label>
                    <div class="flex gap-2"><input class="input font-mono uppercase" id="ach_swift_code" name="ach_swift_code" maxlength="11" autocomplete="off" value="{{ old('ach_swift_code', $b->ach_swift_code) }}" data-lookup-swift><button type="button" class="btn btn-sm" data-lookup-btn="swift">Look up</button></div>
                    @error('ach_swift_code')<p class="field-error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label class="label" for="ach_bank_address">Bank address <span class="font-normal text-slate-400">(for wires)</span></label><input class="input" id="ach_bank_address" name="ach_bank_address" value="{{ old('ach_bank_address', $b->ach_bank_address) }}"></div>
                <div class="sm:col-span-3"><label class="label" for="ach_instructions">Extra instructions for the client (optional)</label><input class="input" id="ach_instructions" name="ach_instructions" value="{{ old('ach_instructions', $b->ach_instructions) }}" placeholder="e.g. Please include the invoice number in the payment reference."></div>
                @if ($achLast4)<label class="flex items-center gap-2 text-xs sm:col-span-3"><input class="check" type="checkbox" name="remove_ach_account" value="1"> Remove the saved account number</label>@endif
                <p class="hint sm:col-span-3">Transfers arrive in your bank outside this app; confirm them from the client’s payment report or record them under Payments. Incoming-wire or ACH fees are entered when you confirm (or later on the payment) and post to <em>Bank &amp; Merchant Fees</em>.</p>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Zelle --}}
            <section class="rounded-lg border border-slate-200">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <label class="flex items-center gap-2 font-medium"><input class="check" type="checkbox" name="payment_methods[]" value="zelle" @checked($on('zelle'))> Zelle</label>
                    <span class="chip {{ in_array('zelle', $ready, true) ? 'chip-green' : 'chip-gray' }}">{{ in_array('zelle', $ready, true) ? 'Details on file' : 'Not set up' }}</span>
                </header>
                <div class="space-y-2 px-4 py-3">
                    <input class="input" name="zelle_recipient_name" value="{{ old('zelle_recipient_name', $b->zelle_recipient_name) }}" placeholder="Recipient name as shown in banking apps" aria-label="Zelle recipient name">
                    <input class="input" name="zelle_email" type="email" value="{{ old('zelle_email', $b->zelle_email) }}" placeholder="Enrolled email" aria-label="Zelle email">
                    <input class="input" name="zelle_phone" value="{{ old('zelle_phone', $b->zelle_phone) }}" placeholder="…or enrolled US mobile" aria-label="Zelle phone">
                    <label class="label" for="zelle_qr">Bank-provided Zelle QR (optional)</label>
                    @if ($zelleQr)
                        <div class="flex items-center gap-3"><img src="{{ $zelleQr }}" alt="Current Zelle QR code" class="h-28 w-28 rounded border border-slate-200 bg-white object-contain p-1">
                            <label class="flex items-center gap-2 text-xs"><input class="check" type="checkbox" name="remove_zelle_qr" value="1"> Remove QR</label></div>
                        <p class="hint">Upload a new image below to replace it.</p>
                    @elseif ($b->zelle_qr_document_id)
                        <p class="alert alert-warn text-xs">The saved QR image could not be read. Upload it again (PNG or JPEG).</p>
                    @endif
                    <input class="input" id="zelle_qr" name="zelle_qr" type="file" accept="image/png,image/jpeg">
                    <p class="hint">Only upload a QR code generated by your bank's Zelle app. Payments happen in the client's bank — this app never processes transfers.</p>
                </div>
            </section>

            {{-- Venmo --}}
            <section class="rounded-lg border border-slate-200">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <label class="flex items-center gap-2 font-medium"><input class="check" type="checkbox" name="payment_methods[]" value="venmo" @checked($on('venmo'))> Venmo</label>
                    <span class="chip {{ in_array('venmo', $ready, true) ? 'chip-green' : 'chip-gray' }}">{{ in_array('venmo', $ready, true) ? 'Details on file' : 'Not set up' }}</span>
                </header>
                <div class="space-y-3 px-4 py-3">
                    <div><label class="label" for="venmo_handle">Venmo business username</label><div class="flex items-center gap-1"><span class="text-slate-500">@</span><input class="input" id="venmo_handle" name="venmo_handle" value="{{ old('venmo_handle', $b->venmo_handle) }}" placeholder="YourBusiness" autocomplete="off"></div>
                        @error('venmo_handle')<p class="field-error">{{ $message }}</p>@enderror
                        <p class="hint">Use a Venmo <strong>business</strong> profile — personal profiles may not accept payments for goods and services.</p></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label" for="venmo_fee_percent">Fee (%)</label><input class="input" id="venmo_fee_percent" name="venmo_fee_percent" type="number" step="0.001" min="0" max="10" value="{{ old('venmo_fee_percent', rtrim(rtrim((string) $b->venmo_fee_percent, '0'), '.') ?: '0') }}"></div>
                        <div><label class="label" for="venmo_fee_fixed">+ fixed ($)</label><input class="input" id="venmo_fee_fixed" name="venmo_fee_fixed" type="number" step="0.01" min="0" max="5" value="{{ old('venmo_fee_fixed', number_format($b->venmo_fee_fixed_cents / 100, 2, '.', '')) }}"></div>
                    </div>
                    <p class="hint">Pre-fills the fee when you confirm a Venmo payment (Venmo business profiles currently charge a percentage plus a fixed amount per payment — check your Venmo fee schedule).</p>
                    <label class="flex items-start gap-2 text-sm"><input type="hidden" name="venmo_auto_confirm" value="0"><input class="check mt-0.5" type="checkbox" name="venmo_auto_confirm" value="1" @checked(old('venmo_auto_confirm', $b->venmo_auto_confirm))> Auto-record payments verified by the Venmo feed (below). Otherwise they wait in the review queue.</label>
                </div>
            </section>
        </div>

        {{-- Check --}}
        <section class="rounded-lg border border-slate-200">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <label class="flex items-center gap-2 font-medium"><input class="check" type="checkbox" name="payment_methods[]" value="check" @checked($on('check'))> Mailed check</label>
                <span class="chip {{ in_array('check', $ready, true) ? 'chip-green' : 'chip-gray' }}">{{ in_array('check', $ready, true) ? 'Details on file' : 'Not set up' }}</span>
            </header>
            <div class="grid gap-2 px-4 py-3 sm:grid-cols-2">
                <input class="input" name="check_payee" value="{{ old('check_payee', $b->check_payee) }}" placeholder="Make checks payable to" aria-label="Check payee">
                <textarea class="input" name="check_address" rows="3" placeholder="Mailing address" aria-label="Check mailing address">{{ old('check_address', $b->check_address) }}</textarea>
                <p class="hint sm:col-span-2">Receipt-date policy: a check is recorded on the date you receive it, held in Undeposited Funds until you mark it deposited.</p>
            </div>
        </section>

        <label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="confirm_change" value="1" required> I verified these instructions with our bank. Changes are audited and require recent sign-in.</label>
        <div><button class="btn btn-primary">Save payment methods</button></div>
    </fieldset>
</form>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
<form id="stripe" method="post" action="{{ $bu }}/settings/stripe" class="card scroll-mt-4">@csrf
    <div class="card-head"><h2>Stripe (card payments)</h2>
        @if ($b->stripe_secret_key_encrypted)<span class="chip {{ $b->stripe_live ? 'chip-green' : 'chip-amber' }}">{{ $b->stripe_live ? 'Live' : 'Test mode' }} · {{ $b->stripe_account_label }}</span>@else<span class="chip chip-gray">Not connected</span>@endif</div>
    <fieldset class="card-body space-y-3" {{ $dis }}>
        <div><label class="label" for="stripe_secret_key">Secret key</label><input class="input font-mono" id="stripe_secret_key" name="stripe_secret_key" autocomplete="off" placeholder="{{ $b->stripe_secret_key_encrypted ? 'Saved — leave blank to keep' : 'sk_live_… or a restricted rk_live_… key' }}">
            <p class="hint">Stored encrypted. A restricted key needs write access to Checkout Sessions, PaymentIntents and Customers, and read access to Balance transactions.</p></div>
        <div><label class="label" for="stripe_webhook_secret">Webhook signing secret</label><input class="input font-mono" id="stripe_webhook_secret" name="stripe_webhook_secret" autocomplete="off" placeholder="{{ $b->stripe_webhook_secret_encrypted ? 'Saved — leave blank to keep' : 'whsec_…' }}">
            @error('stripe_webhook_secret')<p class="field-error">{{ $message }}</p>@enderror
            <p class="hint">In Stripe → Developers → Webhooks, add <code class="break-all">{{ $stripeWebhookUrl }}</code> with events <code>checkout.session.completed</code>, <code>payment_intent.succeeded</code> and <code>payment_intent.payment_failed</code>. Payments are also verified when the client returns from Stripe, so the webhook is a safety net.</p></div>
        <div><label class="label" for="stripe_deposit_account_id">Record card payments to</label><select class="input" id="stripe_deposit_account_id" name="stripe_deposit_account_id"><option value="">Operating bank account (default)</option>@foreach ($cashAccounts as $a)<option value="{{ $a->id }}" @selected($b->stripe_deposit_account_id === $a->id)>{{ $a->label() }}</option>@endforeach</select>
            <p class="hint">Net of Stripe’s fee. Choose a separate “Stripe balance” asset account if you reconcile payouts.</p></div>
        <div class="flex flex-wrap gap-2"><button class="btn btn-primary">{{ $b->stripe_secret_key_encrypted ? 'Save Stripe settings' : 'Connect Stripe' }}</button>
            @if ($b->stripe_secret_key_encrypted)<button class="btn btn-danger" name="disconnect" value="1" data-confirm="Disconnect Stripe? Clients will no longer be able to pay by card.">Disconnect</button>@endif</div>
    </fieldset>
</form>

<div id="venmo-feed" class="card scroll-mt-4">
    <div class="card-head"><h2>Venmo payment feed (API)</h2><span class="chip {{ $b->venmo_api_token_hash ? 'chip-green' : 'chip-gray' }}">{{ $b->venmo_api_token_hash ? 'Token active' : 'No token' }}</span></div>
    <div class="card-body space-y-3 text-sm">
        <p class="text-slate-600">Venmo doesn’t offer a public payments API, so connect an automation you control (for example an email parser on your Venmo “paid you” notifications) to post each receipt here. Receipts are matched to invoices by the invoice number in the Venmo note — or by amount to an open Venmo report — then recorded automatically or queued for your review.</p>
        @if (session('venmo_token'))
            <div class="alert alert-info"><strong>Copy this token now — it won’t be shown again.</strong>
                <div class="mt-2 flex items-center gap-2"><code class="break-all">{{ session('venmo_token') }}</code><button type="button" class="btn btn-sm" data-copy="{{ session('venmo_token') }}">Copy</button></div></div>
        @endif
        <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs text-slate-100">POST {{ $venmoApiUrl }}
Authorization: Bearer vnm_…
Content-Type: application/json

{"transaction_id": "4012345678901234567", "amount": "250.00",
 "date": "{{ $b->localToday()->toDateString() }}", "note": "Invoice {{ $b->invoice_prefix }}0042",
 "sender": "Jane Client", "fee": "4.85"}</pre>
        <p class="hint">Responses: <code>201 recorded</code> or <code>201 pending_review</code>, <code>202 unmatched</code> (logged in the audit trail), <code>200 duplicate</code>. <code>fee</code> is optional — your Venmo fee settings are used when it’s missing.</p>
        @if ($canManage)
        <div class="flex flex-wrap gap-2">
            <form method="post" action="{{ $bu }}/settings/venmo-token" @if($b->venmo_api_token_hash) data-confirm="Create a new token? The current one stops working immediately." @endif>@csrf<button class="btn">{{ $b->venmo_api_token_hash ? 'Rotate token' : 'Create token' }}</button></form>
            @if ($b->venmo_api_token_hash)<form method="post" action="{{ $bu }}/settings/venmo-token" data-confirm="Revoke the Venmo feed token?">@csrf<input type="hidden" name="revoke" value="1"><button class="btn btn-danger">Revoke</button></form>@endif
        </div>
        @if ($b->venmo_api_token_created_at)<p class="hint">Current token created {{ $b->venmo_api_token_created_at->timezone($b->timezone)->format('M j, Y') }}.</p>@endif
        @endif
    </div>
</div>
</div>

<div id="email" class="card mt-4">
    <div class="card-head"><h2>Email delivery</h2><span class="chip {{ $notifyHealth['status'] === 'healthy' ? 'chip-green' : ($notifyHealth['status'] === 'failed' ? 'chip-red' : 'chip-amber') }}">{{ ucfirst($notifyHealth['status']) }}</span></div>
    <div class="card-body grid gap-4 sm:grid-cols-2">
        <form method="post" action="{{ $bu }}/settings/email" class="space-y-2">@csrf
            <fieldset {{ $dis }} class="space-y-2">
                <label class="label" for="sender_name">Sender name</label><input class="input" id="sender_name" name="sender_name" value="{{ $b->sender_name ?: $b->name() }}" required>
                <label class="label" for="sender_email">Sender address (must be approved)</label><input class="input" id="sender_email" name="sender_email" type="email" value="{{ $b->sender_email }}" placeholder="{{ config('finance.notifications.default_from_email') }}">
                <p class="hint">Emails go out as: <code>{{ $fromHeader }}</code></p>
                <button class="btn btn-primary">Save sender</button>
            </fieldset>
        </form>
        @if ($canManage)
        <form method="post" action="{{ $bu }}/settings/email/test" class="space-y-2">@csrf
            <label class="label" for="test-to">Send a test email</label><input class="input" id="test-to" name="to" type="email" value="{{ $ctx->user->email }}" required>
            <button class="btn">Send test</button>
            <p class="hint">{{ $notifyHealth['message'] }}</p>
        </form>
        @endif
    </div>
</div>
@endsection
