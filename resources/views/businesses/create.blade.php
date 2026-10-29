@extends('layouts.guest')
@section('title', 'Create business · McNutt Finance')
@section('content')
<h1>Create a business</h1>
<p class="mt-1 text-sm text-slate-600">You'll be the owner. A standard chart of accounts, roles and a service-agreement template are set up automatically.</p>
<form method="post" action="/businesses" class="card card-body mt-6 space-y-4">
    @csrf
    <div><label class="label" for="legal_name">Legal name</label><input class="input" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" required maxlength="200"></div>
    <div><label class="label" for="display_name">Display name (DBA)</label><input class="input" id="display_name" name="display_name" value="{{ old('display_name') }}" maxlength="200"></div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="entity_type">Entity type</label>
            <select class="input" id="entity_type" name="entity_type">
                @foreach (['llc' => 'LLC', 'sole_proprietor' => 'Sole proprietor', 's_corp' => 'S corporation', 'c_corp' => 'C corporation', 'partnership' => 'Partnership', 'other' => 'Other'] as $k => $v)
                    <option value="{{ $k }}" @selected(old('entity_type') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div><label class="label" for="timezone">Time zone</label>
            <select class="input" id="timezone" name="timezone">
                @foreach (['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu'] as $tz)
                    <option @selected(old('timezone', 'America/New_York') === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div><label class="label" for="public_email">Public billing email</label><input class="input" id="public_email" name="public_email" type="email" value="{{ old('public_email') }}"></div>
    <div class="flex gap-2"><button class="btn btn-primary">Create business</button><a href="/businesses" class="btn">Cancel</a></div>
</form>
@endsection
