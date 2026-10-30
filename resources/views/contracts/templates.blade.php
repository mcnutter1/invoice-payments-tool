@extends('layouts.app')
@section('title', 'Contract templates')
@section('content')
<h1>Contract templates</h1>
<p class="mt-1 text-sm text-slate-500">Format with headings, bold, lists and links, and use “Insert field” for merge fields filled in per agreement. Pasted content is cleaned; scripts, images and styling are removed. Saving a template creates a new template version and never changes existing agreements.</p>
<div class="mt-4 space-y-4">
    @foreach ($templates as $t)
        <details class="card" @if($loop->first) open @endif><summary class="card-head cursor-pointer"><h2>{{ $t->name }} <span class="text-xs text-slate-500">v{{ $t->version }}</span></h2>@include('partials.status', ['s' => $t->active ? 'active' : 'archived'])</summary>
            <form method="post" action="{{ $bu }}/contract-templates/{{ $t->id }}" class="card-body space-y-2">@csrf
                <input class="input" name="name" value="{{ $t->name }}" aria-label="Template name" @disabled(! $ctx->can('contracts.manage'))>
                @include('partials.rich-editor', ['name' => 'body', 'id' => 'tpl-body-'.$t->id, 'value' => \App\Support\RichText::toHtml((string) $t->body), 'fields' => $fields, 'label' => 'Template body', 'disabled' => ! $ctx->can('contracts.manage'), 'required' => true])
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
            @php $f = fn ($k) => str_repeat('{', 2).$k.str_repeat('}', 2); @endphp
            @include('partials.rich-editor', ['name' => 'body', 'id' => 'tpl-body-new', 'value' => '<h1>Agreement</h1><p>Between '.$f('business.legal_name').' and '.$f('client.legal_name').'.</p>', 'fields' => $fields, 'label' => 'Template body', 'required' => true])
            <button class="btn btn-primary">Create template</button>
        </form>
    </details>
    @endif
</div>
@endsection
