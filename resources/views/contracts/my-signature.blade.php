@extends('layouts.app')
@section('title', 'My signature')
@section('content')
<h1>My signature</h1>
<p class="mt-1 max-w-2xl text-sm text-slate-500">Your saved signature is used when you countersign agreements for {{ $biz->name() }}. It’s private to your account and never shown to clients until you sign.</p>
<div class="mt-4 grid max-w-4xl gap-4 md:grid-cols-2">
    <div class="card">
        <div class="card-head"><h2>Saved signature</h2></div>
        <div class="card-body">
            @if ($savedSignature)
                <div class="flex min-h-[8rem] items-center justify-center rounded-md border border-slate-200 bg-[repeating-conic-gradient(#f1f5f9_0%_25%,#fff_0%_50%)] bg-[length:16px_16px] p-4">
                    <img src="{{ $savedSignature }}" alt="Your saved signature" class="max-h-28 max-w-full object-contain">
                </div>
                <form method="post" action="{{ $bu }}/my-signature/delete" class="mt-3" data-confirm="Remove your saved signature?">@csrf<button class="btn btn-ghost btn-sm">Remove saved signature</button></form>
            @else
                <div class="empty">No saved signature yet.</div>
            @endif
        </div>
    </div>
    <form method="post" action="{{ $bu }}/my-signature" enctype="multipart/form-data" class="card">@csrf
        <div class="card-head"><h2>{{ $savedSignature ? 'Replace' : 'Upload' }} signature</h2></div>
        <div class="card-body space-y-3">
            <div><label class="label" for="signature">PNG image</label>
                <input class="input" type="file" id="signature" name="signature" accept="image/png" required>
                <p class="hint">A transparent or white background works best. Large images are resized to fit the signature line (max 2 MB).</p></div>
            <button class="btn btn-primary">Save signature</button>
            <p class="hint">You can also draw a signature while countersigning and tick “Save as my signature”.</p>
        </div>
    </form>
</div>
@endsection
