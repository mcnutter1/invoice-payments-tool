<div class="card"><div class="card-head"><h2>Activity</h2></div>
    <ol class="card-body max-h-[36rem] space-y-2 overflow-y-auto text-xs">
    @forelse ($timeline as $t)
        <li class="flex gap-2">
            <span @class(['mt-1 h-2 w-2 flex-none rounded-full', 'bg-slate-400' => $t['kind'] === 'staff', 'bg-brand-600' => $t['kind'] === 'email', 'bg-emerald-500' => $t['kind'] === 'client' && empty($t['automated']), 'bg-slate-300' => $t['kind'] === 'client' && ! empty($t['automated'])]) aria-hidden="true"></span>
            <span @class(['text-slate-400' => ! empty($t['automated'])])>
                <span class="font-medium text-slate-800">{{ $t['label'] }}</span>
                @if (! empty($t['automated']))<span class="chip chip-gray">possibly automated</span>@endif
                <br><span class="text-slate-500">{{ $t['at']->timezone($biz->timezone)->format('M j, g:ia') }} · {{ $t['who'] }}</span>
                @if ($t['detail'])<br><span class="text-slate-500">{{ $t['detail'] }}</span>@endif
            </span>
        </li>
    @empty
        <li class="text-slate-500">No activity.</li>
    @endforelse
    </ol>
</div>
