@php $vid = $v->id ?? 'new'; @endphp
<div><label class="label" for="vn-{{ $vid }}">Name</label><input class="input" id="vn-{{ $vid }}" name="name" value="{{ $v->name }}" required></div>
<div><label class="label" for="ve-{{ $vid }}">Email</label><input class="input" id="ve-{{ $vid }}" name="email" type="email" value="{{ $v->email }}"></div>
<div><label class="label" for="vp-{{ $vid }}">Phone</label><input class="input" id="vp-{{ $vid }}" name="phone" value="{{ $v->phone }}"></div>
<div><label class="label" for="va-{{ $vid }}">Default category</label><select class="input" id="va-{{ $vid }}" name="default_account_id"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($v->default_account_id == $a->id)>{{ $a->label() }}</option>@endforeach</select></div>
<div><label class="label" for="vad-{{ $vid }}">Address</label><textarea class="input" id="vad-{{ $vid }}" name="address" rows="2">{{ $v->address }}</textarea></div>
