@php $ct = $ct ?? null; $p = $ct ? 'ct'.$ct->id.'-' : 'new-'; @endphp
<div><label class="label" for="{{ $p }}name">Name</label><input class="input" id="{{ $p }}name" name="name" value="{{ $ct?->name }}" required maxlength="120"></div>
<div><label class="label" for="{{ $p }}title">Title</label><input class="input" id="{{ $p }}title" name="title" value="{{ $ct?->title }}" maxlength="120"></div>
<div><label class="label" for="{{ $p }}email">Email</label><input class="input" id="{{ $p }}email" name="email" type="email" value="{{ $ct?->email }}"></div>
<div><label class="label" for="{{ $p }}phone">Phone</label><input class="input" id="{{ $p }}phone" name="phone" type="tel" value="{{ $ct?->phone }}" maxlength="40"></div>
<label class="flex items-center gap-2"><input class="check" type="checkbox" name="is_primary_billing" value="1" @checked($ct?->is_primary_billing)> Primary billing contact</label>
<label class="flex items-center gap-2"><input class="check" type="checkbox" name="is_signer" value="1" @checked($ct?->is_signer)> Can sign contracts</label>
