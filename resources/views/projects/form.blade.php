@extends('layouts.app')
@section('title', $project ? 'Edit project' : 'New project')
@section('content')
@include('expenses._tabs', ['active' => 'projects'])
<h1>{{ $project ? 'Edit project' : 'New project' }}</h1>
<form method="post" action="{{ $bu }}/projects{{ $project ? '/'.$project->id : '' }}" class="card card-body mt-4 grid max-w-3xl gap-4 sm:grid-cols-2">@csrf
    <div class="sm:col-span-2"><label class="label" for="name">Name</label><input class="input" id="name" name="name" required maxlength="200" value="{{ old('name', $project?->name) }}" placeholder="e.g. NYC meeting with Mavericks Group"></div>
    <div><label class="label" for="client_id">Client</label><select class="input" id="client_id" name="client_id"><option value="">General business (no client)</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id', $clientId) == $c->id)>{{ $c->display_name }}</option>@endforeach</select></div>
    @if ($project)
        <div><label class="label" for="status">Status</label><select class="input" id="status" name="status"><option value="open" @selected(old('status', $project->status) === 'open')>Open</option><option value="closed" @selected(old('status', $project->status) === 'closed')>Closed</option></select></div>
    @else <div></div> @endif
    <div><label class="label" for="start_date">Start</label><input class="input" type="date" id="start_date" name="start_date" value="{{ old('start_date', $project?->start_date?->toDateString()) }}"></div>
    <div><label class="label" for="end_date">End</label><input class="input" type="date" id="end_date" name="end_date" value="{{ old('end_date', $project?->end_date?->toDateString()) }}"></div>
    <div class="sm:col-span-2"><label class="label" for="description">Description</label><textarea class="input" id="description" name="description" rows="3">{{ old('description', $project?->description) }}</textarea></div>
    <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary">{{ $project ? 'Save' : 'Create project' }}</button><a class="btn" href="{{ $bu }}/projects{{ $project ? '/'.$project->id : '' }}">Cancel</a></div>
</form>
@endsection
