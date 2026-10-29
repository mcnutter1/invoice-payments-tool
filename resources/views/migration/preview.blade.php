@extends('layouts.app')
@section('title', 'Import preview')
@section('content')
@php $c = collect($rows); @endphp
<h1>Preview: {{ $label }}</h1>
<p class="text-sm text-slate-500">{{ $file->original_name }} @if($cutoff) · cutoff {{ $cutoff }} @endif — dry run, nothing has been saved yet.</p>
<div class="mt-3 flex flex-wrap gap-2 text-sm">
    <span class="chip chip-green">{{ $c->where('status', 'new')->count() }} new</span>
    <span class="chip chip-gray">{{ $c->where('status', 'duplicate')->count() }} duplicates</span>
    <span class="chip chip-red">{{ $c->where('status', 'error')->count() }} errors</span>
</div>
<div class="card mt-3 max-h-[32rem] overflow-auto">
    <table class="tbl"><thead><tr><th>Row</th>@foreach (array_keys($rows[0]['data'] ?? []) as $h)<th>{{ $h }}</th>@endforeach<th>Result</th></tr></thead><tbody>
    @foreach (array_slice($rows, 0, 500) as $r)
        <tr><td>{{ $r['row'] }}</td>@foreach ($r['data'] as $v)<td>{{ \Illuminate\Support\Str::limit($v, 40) }}</td>@endforeach
            <td>@include('partials.status', ['s' => $r['status'] === 'new' ? 'pending' : ($r['status'] === 'duplicate' ? 'skipped' : 'failed')]) <span class="text-xs text-slate-500">{{ $r['message'] }}</span></td></tr>
    @endforeach
    </tbody></table>
</div>
<form method="post" action="{{ $bu }}/migration/{{ $kind }}/{{ $doc }}" class="mt-4 flex gap-2" data-confirm="Import {{ $c->where('status', 'new')->count() }} rows?">@csrf
    <input type="hidden" name="cutoff" value="{{ $cutoff }}">
    <button class="btn btn-primary" @disabled(! $c->where('status', 'new')->count())>Import {{ $c->where('status', 'new')->count() }} rows</button>
    <a class="btn" href="{{ $bu }}/migration">Cancel</a>
</form>
@endsection
