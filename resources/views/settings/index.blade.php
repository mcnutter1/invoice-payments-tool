@extends('layouts.app')
@section('title', 'Settings')
@section('content')
@php $dis = $canManage ? '' : 'disabled'; @endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1>Settings</h1>
    <nav class="flex flex-wrap gap-1">
        <a class="btn btn-sm" href="#profile">Profile</a><a class="btn btn-sm" href="#reminders">Reminders</a><a class="btn btn-sm" href="#branding">Branding</a><a class="btn btn-sm" href="#payments">Payment instructions</a><a class="btn btn-sm" href="#email">Email</a>
        @if ($ctx->can('members.manage'))<a class="btn btn-sm" href="{{ $bu }}/settings/members">Members</a>@endif
        @if ($ctx->can('roles.manage'))<a class="btn btn-sm" href="{{ $bu }}/settings/roles">Roles</a>@endif
        @if ($ctx->can('automation.view'))<a class="btn btn-sm" href="{{ $bu }}/automation">Automation</a>@endif
        @if ($ctx->can('audit.view'))<a class="btn btn-sm" href="{{ $bu }}/audit">Audit log</a>@endif
        @if ($ctx->can('journals.manage') && $ctx->can('clients.manage'))<a class="btn btn-sm" href="{{ $bu }}/migration">Data import</a>@endif
        <a class="btn btn-sm" href="{{ $bu }}/vendors">Vendors</a>
    </nav>
</div>

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

<form id="payments" method="post" action="{{ $bu }}/settings/payments" enctype="multipart/form-data" class="card mt-4">@csrf
    <div class="card-head"><h2>Payment instructions</h2>@if ($b->payment_instructions_changed_at)<span class="text-xs text-slate-500">Last changed {{ $b->payment_instructions_changed_at->timezone($b->timezone)->format('M j, Y g:ia') }}</span>@endif</div>
    <fieldset class="card-body grid gap-4 sm:grid-cols-2" {{ $dis }}>
        @if ($pendingSends)<div class="alert alert-warn sm:col-span-2">{{ $pendingSends }} email(s) are queued right now. Invoices always show the instructions they were issued with.</div>@endif
        <fieldset class="sm:col-span-2"><legend class="label">Accepted methods</legend>
            <label class="mr-4 inline-flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="payment_methods[]" value="zelle" @checked(in_array('zelle', $b->allowedMethods(), true))> Zelle</label>
            <label class="inline-flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="payment_methods[]" value="check" @checked(in_array('check', $b->allowedMethods(), true))> Mailed check</label>
        </fieldset>
        <div class="space-y-2"><h3>Zelle</h3>
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
        <div class="space-y-2"><h3>Mailed check</h3>
            <input class="input" name="check_payee" value="{{ old('check_payee', $b->check_payee) }}" placeholder="Make checks payable to" aria-label="Check payee">
            <textarea class="input" name="check_address" rows="3" placeholder="Mailing address" aria-label="Check mailing address">{{ old('check_address', $b->check_address) }}</textarea>
            <p class="hint">Receipt-date policy: a check is recorded on the date you receive it, held in Undeposited Funds until you mark it deposited.</p>
        </div>
        <label class="flex items-start gap-2 text-sm sm:col-span-2"><input class="check mt-0.5" type="checkbox" name="confirm_change" value="1" required> I verified these instructions with our bank. Changes are audited and require recent sign-in.</label>
        <div><button class="btn btn-primary">Save payment instructions</button></div>
    </fieldset>
</form>

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
