@extends('layouts.app')
@section('title', $client->exists ? 'Edit client' : 'New client')
@section('content')
<h1>{{ $client->exists ? 'Edit '.$client->display_name : 'New client' }}</h1>
<form method="post" action="{{ $bu }}/clients{{ $client->exists ? '/'.$client->id : '' }}" class="mt-4 max-w-3xl space-y-4">
    @csrf
    <div class="card card-body space-y-4">
        <fieldset class="flex gap-4"><legend class="label">Type</legend>
            @foreach (['company' => 'Company', 'individual' => 'Individual'] as $k => $v)
                <label class="flex items-center gap-2 text-sm"><input type="radio" name="type" value="{{ $k }}" @checked(old('type', $client->type) === $k)> {{ $v }}</label>
            @endforeach
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="display_name">Display name</label><input class="input" id="display_name" name="display_name" required value="{{ old('display_name', $client->display_name) }}" @error('display_name') aria-invalid="true" aria-describedby="display_name-err" @enderror>@error('display_name')<p class="field-error" id="display_name-err">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="legal_name">Legal name</label><input class="input" id="legal_name" name="legal_name" value="{{ old('legal_name', $client->legal_name) }}"></div>
            <div><label class="label" for="email">Billing email</label><input class="input" id="email" name="email" type="email" value="{{ old('email', $client->email) }}">@error('email')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="phone">Phone</label><input class="input" id="phone" name="phone" value="{{ old('phone', $client->phone) }}"></div>
            @unless ($client->exists)
                <div><label class="label" for="contact_name">Primary contact name</label><input class="input" id="contact_name" name="contact_name" value="{{ old('contact_name') }}"><p class="hint">Also used as the default contract signer.</p></div>
            @endunless
        </div>
    </div>
    <div class="card card-body grid gap-6 sm:grid-cols-2">
        @foreach (['billing' => 'Billing address', 'service' => 'Service address'] as $p => $label)
            <fieldset class="space-y-2"><legend class="label">{{ $label }}</legend>
                <label class="sr-only" for="{{ $p }}_line1">{{ $label }} line 1</label><input class="input" id="{{ $p }}_line1" name="{{ $p }}_line1" placeholder="Street" value="{{ old($p.'_line1', $client->{$p.'_line1'}) }}">
                <label class="sr-only" for="{{ $p }}_line2">{{ $label }} line 2</label><input class="input" id="{{ $p }}_line2" name="{{ $p }}_line2" placeholder="Suite / unit" value="{{ old($p.'_line2', $client->{$p.'_line2'}) }}">
                <div class="grid grid-cols-3 gap-2">
                    <input class="input col-span-1" name="{{ $p }}_city" placeholder="City" aria-label="{{ $label }} city" value="{{ old($p.'_city', $client->{$p.'_city'}) }}">
                    <input class="input" name="{{ $p }}_state" placeholder="State" aria-label="{{ $label }} state" value="{{ old($p.'_state', $client->{$p.'_state'}) }}">
                    <input class="input" name="{{ $p }}_postal_code" placeholder="ZIP" aria-label="{{ $label }} ZIP" value="{{ old($p.'_postal_code', $client->{$p.'_postal_code'}) }}">
                </div>
            </fieldset>
        @endforeach
    </div>
    <div class="card card-body grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="default_terms_days">Payment terms (days)</label><input class="input" id="default_terms_days" name="default_terms_days" type="number" min="0" max="365" value="{{ old('default_terms_days', $client->default_terms_days) }}" placeholder="Business default ({{ $biz->default_terms_days }})"></div>
        <div><label class="label" for="external_id">External ID</label><input class="input" id="external_id" name="external_id" value="{{ old('external_id', $client->external_id) }}"></div>
        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="tax_exempt" value="0"><input class="check" type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt', $client->tax_exempt))> Tax exempt (no sales tax on invoices)</label>
        <div class="sm:col-span-2"><label class="label" for="notes">Private notes</label><textarea class="input" id="notes" name="notes" rows="3">{{ old('notes', $client->notes) }}</textarea><p class="hint">Never shown to the client.</p></div>
    </div>
    @if (session('duplicate'))
        <label class="flex items-center gap-2 text-sm"><input class="check" type="checkbox" name="confirm_duplicate" value="1"> Create anyway</label>
    @endif
    <div class="flex gap-2"><button class="btn btn-primary">Save client</button><a href="{{ $bu }}/clients{{ $client->exists ? '/'.$client->id : '' }}" class="btn">Cancel</a></div>
</form>
@endsection
