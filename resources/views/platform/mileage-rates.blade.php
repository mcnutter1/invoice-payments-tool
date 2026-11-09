@extends('layouts.platform')
@section('title', 'Application settings · Mileage rates')
@section('content')
<h1>IRS standard mileage rates</h1>
<p class="mt-1 text-sm text-slate-600">Used by every business to price business mileage. New rates are picked up from <a href="{{ $source }}" target="_blank" rel="noopener">irs.gov</a> weekly; you can also add one by hand. Trips recorded at a provisional rate can be re-priced from the Mileage page.</p>
<div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
    <form method="post" action="/platform/mileage-rates/sync">@csrf<button class="btn">Check IRS now</button></form>
    @if ($lastSync)
        <span class="{{ ($lastSync['ok'] ?? false) ? 'text-slate-600' : 'text-red-700' }}">Last check {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->diffForHumans() }}: {{ ($lastSync['ok'] ?? false) ? (empty($lastSync['added']) ? 'no new rates' : 'added '.implode(', ', $lastSync['added'])) : 'failed — '.($lastSync['error'] ?? 'unknown error') }}</span>
    @else
        <span class="text-slate-500">Not checked yet.</span>
    @endif
</div>
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card overflow-x-auto lg:col-span-2">
        <table class="tbl"><thead><tr><th>Effective from</th><th class="num">Business rate</th><th>Source</th><th></th></tr></thead><tbody>
        @foreach ($rates as $r)
            <tr><td>{{ $r->effective_from->format('M j, Y') }}</td><td class="num">{{ rtrim(rtrim(number_format($r->business_rate_millis / 10, 1), '0'), '.') }}¢ / mile</td>
                <td><span class="chip {{ $r->source === 'irs.gov' ? 'chip-green' : ($r->source === 'manual' ? 'chip-violet' : 'chip-gray') }}">{{ $r->source }}</span></td>
                <td class="text-right">@if($r->source === 'manual')<form method="post" action="/platform/mileage-rates/{{ $r->id }}/delete" data-confirm="Remove this rate?">@csrf<button class="btn btn-ghost btn-sm">Remove</button></form>@endif</td></tr>
        @endforeach
        </tbody></table>
    </div>
    <form method="post" action="/platform/mileage-rates" class="card card-body space-y-3">@csrf
        <h2>Add a rate</h2>
        <div><label class="label" for="effective_from">Effective from</label><input class="input" type="date" id="effective_from" name="effective_from" required></div>
        <div><label class="label" for="cents">Cents per mile</label><input class="input" type="number" step="0.1" min="1" max="500" id="cents" name="cents" required></div>
        <div><label class="label" for="note">Note</label><input class="input" id="note" name="note" maxlength="255" placeholder="e.g. IRS Notice 2026-xx"></div>
        <button class="btn btn-primary">Add rate</button>
    </form>
</div>
@endsection
