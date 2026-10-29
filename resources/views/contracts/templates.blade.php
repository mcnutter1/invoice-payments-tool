@extends('layouts.app')
@section('title', 'Contract templates')
@section('content')
<h1>Contract templates</h1>
<p class="mt-1 text-sm text-slate-500">Plain text with # headings, **bold**, - lists and merge fields. No HTML or scripts. Editing a template creates a new template version and never changes existing agreements.</p>
<p class="mt-2 text-xs text-slate-600">Merge fields: @foreach ($fields as $f)<code class="mr-2">{{ str_repeat('{', 2).$f.str_repeat('}', 2) }}</code>@endforeach</p>
<div class="mt-4 space-y-4">
    @foreach ($templates as $t)
        <details class="card" @if($loop->first) open @endif><summary class="card-head cursor-pointer"><h2>{{ $t->name }} <span class="text-xs text-slate-500">v{{ $t->version }}</span></h2>@include('partials.status', ['s' => $t->active ? 'active' : 'archived'])</summary>
            <form method="post" action="{{ $bu }}/contract-templates/{{ $t->id }}" class="card-body space-y-2">@csrf
                <input class="input" name="name" value="{{ $t->name }}" aria-label="Template name" @disabled(! $ctx->can('contracts.manage'))>
                <textarea class="input font-mono text-xs" name="body" rows="18" aria-label="Template body" @disabled(! $ctx->can('contracts.manage'))>{{ $t->body }}</textarea>
                @if ($ctx->can('contracts.manage'))
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="active" value="0"><input class="check" type="checkbox" name="active" value="1" @checked($t->active)> Active</label>
                <button class="btn btn-primary">Save new version</button>
                @endif
            </form>
        </details>
    @endforeach
    @if ($ctx->can('contracts.manage'))
    <details class="card"><summary class="card-head cursor-pointer"><h2>+ New template</h2></summary>
        <form method="post" action="{{ $bu }}/contract-templates" class="card-body space-y-2">@csrf
            <input class="input" name="name" placeholder="Name" required aria-label="Template name">
            <textarea class="input font-mono text-xs" name="body" rows="12" required aria-label="Template body"># Agreement

Between @{{business.legal_name}} and @{{client.legal_name}}.</textarea>
            <button class="btn btn-primary">Create template</button>
        </form>
    </details>
    @endif
</div>
@endsection
