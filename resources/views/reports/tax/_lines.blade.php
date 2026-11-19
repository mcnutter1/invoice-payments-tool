<section class="card mt-4">
    <div class="card-head"><h2>{{ $title }}</h2>@if ($sub)<span class="text-xs text-slate-500">{{ $sub }}</span>@endif</div>
    <div class="overflow-x-auto">
    <table class="tbl"><thead><tr><th>Line</th><th>How it’s calculated</th><th>Return reference</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach ($lines as $l)
        <tr @class(['total' => $l['strong']])>
            <td @class(['font-semibold' => $l['strong']])>{{ $l['label'] }}</td>
            <td class="text-xs text-slate-500">{{ $l['formula'] }}</td>
            <td class="whitespace-nowrap text-xs text-slate-500">{{ $l['ref'] }}</td>
            <td @class(['num', 'font-semibold' => $l['strong']])>{{ $M($l['amount']) }}</td>
        </tr>
    @endforeach
    </tbody></table>
    </div>
</section>
