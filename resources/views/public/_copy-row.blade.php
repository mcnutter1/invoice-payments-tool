<div class="flex items-center justify-between gap-3 px-3 py-2">
    <dt class="shrink-0 text-slate-500">{{ $label }}</dt>
    <dd class="flex min-w-0 items-center gap-2">
        <span class="truncate {{ ($mono ?? true) ? 'font-mono' : 'font-medium' }}">{{ $value }}</span>
        @if ($copy ?? true)<button type="button" class="btn btn-sm" data-copy="{{ $copyValue ?? $value }}" @if(! empty($track)) data-track="{{ $track }}" data-track-url="{{ $trackUrl }}" @endif aria-label="Copy {{ strtolower($label) }}">Copy</button>@endif
    </dd>
</div>
