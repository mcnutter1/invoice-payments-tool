{{-- Recipient picker: client contacts as checkboxes + free-form extra addresses. Server merges both into `recipients`. --}}
@php
    $options = $options ?? [];
    $selected = array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), is_array($selected ?? null) ? $selected : preg_split('/[,;\s]+/', (string) ($selected ?? '')))));
    if (! $selected && ($defaultBilling ?? false)) $selected = array_column(array_filter($options, fn ($o) => $o['billing']), 'email');
    $known = array_column($options, 'email');
    $extra = implode(', ', array_diff($selected, $known));
@endphp
<div data-recipient-picker @if(! empty($clientSelect)) data-client-select="{{ $clientSelect }}" @endif>
    <div class="label">{{ $label ?? 'Email to' }}</div>
    <div class="space-y-1 rounded-md border border-slate-200 bg-white p-2" data-recipient-options>
        @forelse ($options as $o)
            <label class="flex flex-wrap items-center gap-x-2 gap-y-0.5 rounded px-1 py-1 text-sm hover:bg-slate-50">
                <input class="check" type="checkbox" name="recipient_emails[]" value="{{ $o['email'] }}" @checked(in_array($o['email'], $selected, true))>
                <span class="font-medium text-slate-800">{{ $o['label'] }}</span>
                <span class="text-slate-500">{{ $o['email'] }}</span>
                @if ($o['billing'])<span class="chip chip-blue">Billing</span>@endif
            </label>
        @empty
            <p class="px-1 py-1 text-xs text-slate-500">This client has no contacts with an email address. Add one in the client profile, or type an address below.</p>
        @endforelse
    </div>
    <input class="input mt-2" @if(! empty($id)) id="{{ $id }}" @endif name="recipients" value="{{ $extra }}" placeholder="Other email addresses (comma-separated)" aria-label="Other email addresses">
    <p class="hint">{{ $hint ?? 'Each recipient gets their own secure link.' }}</p>
</div>
