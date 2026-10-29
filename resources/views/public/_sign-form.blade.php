<form method="post" action="{{ $action }}" class="card card-body space-y-4" data-sign-form>
    @csrf
    <input type="hidden" name="document_sha256" value="{{ $version->sha256 }}">
    <h2>Sign</h2>
    <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700" id="consent-text">{{ $consent }}</div>
    <label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="consent" value="1" required aria-describedby="consent-text"> I agree to use electronic records and signatures.</label>
    <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="typed_name">Full legal name</label><input class="input" id="typed_name" name="typed_name" required value="{{ old('typed_name', $recipient->name) }}" autocomplete="name" data-typed-name></div>
        <div><label class="label" for="typed_title">Title (if signing for an organization)</label><input class="input" id="typed_title" name="typed_title" value="{{ old('typed_title', $recipient->title) }}"></div>
    </div>
    <label class="flex items-start gap-2 text-sm"><input class="check mt-0.5" type="checkbox" name="authority" value="1" required> I am authorized to sign this agreement{{ $recipient->title ? '' : ' (for myself or the organization I represent)' }}.</label>
    <fieldset><legend class="label">Signature</legend>
        <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="radio" name="signature_kind" value="type" checked data-sig-kind> Type</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="signature_kind" value="draw" data-sig-kind> Draw</label>
    </fieldset>
    <div data-sig-type class="rounded-md border border-dashed border-slate-300 bg-white px-4 py-3 font-serif text-2xl italic text-slate-800" aria-live="polite" data-sig-preview>{{ old('typed_name', $recipient->name) }}</div>
    <div data-sig-draw hidden>
        <canvas width="600" height="160" class="w-full touch-none rounded-md border border-slate-300 bg-white" aria-label="Draw your signature with mouse or finger" data-sig-canvas></canvas>
        <button type="button" class="btn btn-sm mt-1" data-sig-clear>Clear</button>
        <input type="hidden" name="signature_data" data-sig-data>
    </div>
    <button class="btn btn-primary w-full sm:w-auto" data-confirm="Sign this agreement electronically? This cannot be undone.">Sign agreement</button>
    <p class="hint">Your signature is bound to this exact document (SHA-256 shown above), with a timestamp, IP address and browser details recorded in the signature certificate.</p>
</form>
