{{-- Branded agreement sheet (web). Expects: $business, $contract (with client), $version, $html, $logo, $accent; optional $actions, $scroll. --}}
@php
    $ac = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($accent ?? '')) ? $accent : '#1f5f8b';
    $client = $contract->client;
    $start = $contract->term_start_date ?? $contract->effective_date;
    $hasTitle = $html && preg_match('/^\s*<h1\b/i', $html);
@endphp
<article class="doc-sheet" style="--accent: {{ $ac }}">
    <div class="doc-bar"></div>
    <header class="doc-head">
        <div class="min-w-0">
            @if ($logo)<img src="{{ $logo }}" alt="{{ $business->name() }}" class="h-10 max-w-[8rem] object-contain sm:h-12 sm:max-w-[11rem]">
            @else<div class="break-words text-base font-semibold sm:text-lg" style="color: {{ $ac }}">{{ $business->name() }}</div>@endif
        </div>
        <div class="shrink-0 text-right">
            <div class="doc-kicker">Agreement</div>
            <div class="font-semibold text-slate-900">{{ $contract->number }}</div>
            <div class="text-xs text-slate-500">Version {{ $version->version }}</div>
            @isset($actions)<div class="mt-2 flex justify-end gap-2">{{ $actions }}</div>@endisset
        </div>
    </header>
    @unless ($hasTitle)<h1 class="doc-title">{{ $version->title }}</h1>@endunless
    <div class="doc-parties">
        <div>
            <div class="doc-label">Provider</div>
            <div class="font-semibold text-slate-900">{{ $business->legal_name ?: $business->name() }}</div>
            @foreach ($business->addressLines() as $line)<div>{{ $line }}</div>@endforeach
            @if ($business->public_email)<div>{{ $business->public_email }}</div>@endif
        </div>
        <div>
            <div class="doc-label">Client</div>
            <div class="font-semibold text-slate-900">{{ $client->legal_name ?: $client->display_name }}</div>
            @foreach ($client->billingAddressLines() as $line)<div>{{ $line }}</div>@endforeach
            @if ($client->email)<div>{{ $client->email }}</div>@endif
        </div>
    </div>
    <dl class="doc-terms">
        <div><dt>Effective</dt><dd>{{ $contract->effective_date?->format('M j, Y') ?? '—' }}</dd></div>
        <div><dt>Term</dt><dd>{{ $start ? $start->format('M j, Y').' – '.($contract->term_end_date?->format('M j, Y') ?? 'ongoing') : '—' }}</dd></div>
        <div class="col-span-2 sm:col-span-1"><dt>Agreement</dt><dd>{{ $contract->number }} · v{{ $version->version }}</dd></div>
    </dl>
    @if ($html)
        <div @class(['prose-doc doc-body', 'sm:max-h-[75vh] sm:overflow-auto' => $scroll ?? false]) @if($scroll ?? false) tabindex="0" @endif aria-label="Agreement text">{!! $html !!}</div>
    @else
        <p class="doc-body">Open the PDF to review the full document.</p>
    @endif
    @isset($foot)<div class="doc-foot">{{ $foot }}</div>@endisset
</article>
