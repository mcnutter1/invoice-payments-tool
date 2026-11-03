{{-- Bell toggle for automatic client reminders. Expects $action, $on, $hint. --}}
@php $tip = 'Automatic reminders '.($on ? 'on' : 'off').($hint ? ' ('.$hint.')' : '').' — click to turn '.($on ? 'off' : 'on'); @endphp
<form method="post" action="{{ $action }}">@csrf
    <button @class(['btn', 'text-brand-600' => $on, 'btn-ghost text-slate-400' => ! $on]) title="{{ $tip }}" aria-label="{{ $tip }}" aria-pressed="{{ $on ? 'true' : 'false' }}">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
            @unless ($on)<path d="M3 3l18 18"/>@endunless
        </svg>
    </button>
</form>
