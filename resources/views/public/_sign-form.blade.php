<form method="post" action="{{ $action }}" class="card card-body space-y-4" data-sign-form>
    @csrf
    <input type="hidden" name="document_sha256" value="{{ $version->sha256 }}">
    <h2>Sign</h2>
    <div class="max-h-40 overflow-auto rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700" id="consent-text" tabindex="0">{{ $consent }}</div>
    <label class="flex items-start gap-3 text-sm"><input class="check-lg mt-0.5" type="checkbox" name="consent" value="1" required aria-describedby="consent-text"> <span>I agree to use electronic records and signatures.</span></label>
    <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="typed_name">Full legal name</label><input class="input" id="typed_name" name="typed_name" required value="{{ old('typed_name', $recipient->name) }}" autocomplete="name" autocapitalize="words" data-typed-name></div>
        <div><label class="label" for="typed_title">Title (if signing for an organization)</label><input class="input" id="typed_title" name="typed_title" value="{{ old('typed_title', $recipient->title) }}" autocomplete="organization-title"></div>
    </div>
    <label class="flex items-start gap-3 text-sm"><input class="check-lg mt-0.5" type="checkbox" name="authority" value="1" required> <span>I am authorized to sign this agreement{{ $recipient->title ? '' : ' (for myself or the organization I represent)' }}.</span></label>
    <fieldset>
        <legend class="label">Signature</legend>
        <div class="inline-flex w-full rounded-md border border-slate-300 p-0.5 sm:w-auto" role="radiogroup">
            <label class="sig-tab"><input class="sr-only" type="radio" name="signature_kind" value="type" checked data-sig-kind><span>Type</span></label>
            <label class="sig-tab"><input class="sr-only" type="radio" name="signature_kind" value="draw" data-sig-kind><span>Draw</span></label>
        </div>
    </fieldset>
    <div data-sig-type class="min-h-[4rem] break-words rounded-md border border-dashed border-slate-300 bg-white px-4 py-3 font-serif text-3xl italic text-slate-800" aria-live="polite" data-sig-preview>{{ old('typed_name', $recipient->name) }}</div>
    <div data-sig-draw hidden>
        <div class="relative">
            <canvas class="block h-44 w-full touch-none select-none rounded-md border border-slate-300 bg-white sm:h-40" aria-label="Signature pad: draw your signature with your finger, stylus or mouse" data-sig-canvas></canvas>
            <div class="pointer-events-none absolute inset-x-6 bottom-8 border-b border-slate-300" aria-hidden="true"></div>
            <span class="pointer-events-none absolute bottom-9 left-6 text-lg text-slate-300" aria-hidden="true">&times;</span>
        </div>
        <div class="mt-2 flex items-center justify-between gap-2">
            <p class="text-xs text-slate-500">Sign with your finger or mouse. Turn your phone sideways for more room.</p>
            <button type="button" class="btn btn-sm" data-sig-clear>Clear</button>
        </div>
        <input type="hidden" name="signature_data" data-sig-data>
    </div>
    <button class="btn btn-primary w-full py-3 text-base sm:w-auto sm:py-2 sm:text-sm" data-confirm="Sign this agreement electronically? This cannot be undone.">Sign agreement</button>
    <p class="hint">Your signature is bound to this exact document (SHA-256 shown above), with a timestamp, IP address and browser details recorded in the signature certificate.</p>
</form>
