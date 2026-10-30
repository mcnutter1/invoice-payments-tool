{{-- Agreement sheet (web). Expects: $business, $contract (with client), $version, $html, $logo, $accent; optional $actions, $foot. --}}
@php
    $ac = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($accent ?? '')) ? $accent : '#1f5f8b';
    $client = $contract->client;
    $start = $contract->term_start_date ?? $contract->effective_date;
    $hasTitle = $html && preg_match('/^\s*<h1\b[^>]*>(.*?)<\/h1>/is', $html, $titleMatch);
    // The template's leading heading becomes the document title above the parties block.
    if ($hasTitle) $html = substr($html, strlen($titleMatch[0]));
@endphp
<article class="doc-sheet" style="--accent: {{ $ac }}">
    <header class="doc-head">
        <div class="min-w-0">
            @if ($logo)<img src="{{ $logo }}" alt="{{ $business->name() }}" class="h-9 max-w-[8rem] object-contain sm:h-11 sm:max-w-[10rem]">
            @else<div class="doc-brand">{{ $business->name() }}</div>@endif
        </div>
        <div class="shrink-0 text-right text-xs leading-5 text-slate-500">
            <div>Agreement No. <span class="font-medium text-slate-700">{{ $contract->number }}</span></div>
            <div>Version {{ $version->version }}</div>
            @isset($actions)<div class="mt-2 flex justify-end gap-2">{{ $actions }}</div>@endisset
        </div>
    </header>
    <div class="doc-inner">
        <h1 class="doc-title">@if ($hasTitle){!! $titleMatch[1] !!}@else{{ $version->title }}@endif</h1>
        <div class="doc-parties">
            <div>
                <div class="doc-label">Provider</div>
                <div class="doc-party">{{ $business->legal_name ?: $business->name() }}</div>
                @foreach ($business->addressLines() as $line)<div>{{ $line }}</div>@endforeach
                @if ($business->public_email)<div>{{ $business->public_email }}</div>@endif
            </div>
            <div>
                <div class="doc-label">Client</div>
                <div class="doc-party">{{ $client->legal_name ?: $client->display_name }}</div>
                @foreach ($client->billingAddressLines() as $line)<div>{{ $line }}</div>@endforeach
                @if ($client->email)<div>{{ $client->email }}</div>@endif
            </div>
        </div>
        <dl class="doc-terms">
            <div><dt>Effective date</dt><dd>{{ $contract->effective_date?->format('F j, Y') ?? '—' }}</dd></div>
            <div><dt>Term</dt><dd>{{ $start ? $start->format('F j, Y').' – '.($contract->term_end_date?->format('F j, Y') ?? 'until terminated') : '—' }}</dd></div>
        </dl>
        @if ($html)
            <div class="doc-body" aria-label="Agreement text">{!! $html !!}</div>
        @else
            <p class="doc-body">Open the PDF to review the full document.</p>
        @endif
    </div>
    @isset($foot)<div class="doc-foot">{{ $foot }}</div>@endisset
</article>
