@php
    $pid = $p->id ?? 'new';
    $dlg = 'product-'.$pid;
    $v = fn (string $f) => old('_dialog') === $dlg ? old($f, $p->$f) : $p->$f;
    $section = 'text-xs font-semibold uppercase tracking-wider text-slate-500';
@endphp
<dialog id="{{ $dlg }}" class="modal" aria-labelledby="{{ $dlg }}-title" @if(old('_dialog') === $dlg && $errors->any()) data-open @endif>
    <form method="post" action="{{ $action }}">@csrf
        <input type="hidden" name="_dialog" value="{{ $dlg }}">
        <div class="modal-head">
            <div class="min-w-0">
                <h2 id="{{ $dlg }}-title" class="truncate">{{ $p->exists ? 'Edit '.$p->name : 'New product or service' }}</h2>
                <p class="text-sm text-slate-500">{{ $p->exists ? 'Changes apply to future drafts only. Issued invoices are unchanged.' : 'Saved items can be picked when you add invoice lines.' }}</p>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Close">
                <svg aria-hidden="true" viewBox="0 0 20 20" class="h-5 w-5 fill-current"><path d="M5.3 5.3a1 1 0 0 1 1.4 0L10 8.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 10l3.3 3.3a1 1 0 0 1-1.4 1.4L10 11.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 10 5.3 6.7a1 1 0 0 1 0-1.4Z"/></svg>
            </button>
        </div>

        <div class="modal-body space-y-6">
            @if (old('_dialog') === $dlg && $errors->any())
                <div class="alert alert-warn"><ul class="list-disc pl-5">@foreach ($errors->all() as $msg)<li>{{ $msg }}</li>@endforeach</ul></div>
            @endif

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="{{ $section }} mb-3">Details</legend>
                <div class="sm:col-span-2"><label class="label" for="name-{{ $pid }}">Name</label><input class="input" id="name-{{ $pid }}" name="name" value="{{ $v('name') }}" required autofocus></div>
                <div>
                    <span class="label">Type</span>
                    <div class="seg w-full">
                        @foreach (['service' => 'Service', 'product' => 'Product'] as $k => $label)
                            <label class="seg-item flex-1 cursor-pointer text-center has-[:checked]:bg-white has-[:checked]:text-slate-900 has-[:checked]:shadow-sm">
                                <input class="sr-only" type="radio" name="kind" value="{{ $k }}" @checked(($v('kind') ?: 'service') === $k)>{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div><label class="label" for="sku-{{ $pid }}">SKU / code <span class="font-normal text-slate-500">(optional)</span></label><input class="input" id="sku-{{ $pid }}" name="sku" value="{{ $v('sku') }}"></div>
                <div class="sm:col-span-2"><label class="label" for="desc-{{ $pid }}">Description <span class="font-normal text-slate-500">(shown on invoices)</span></label><textarea class="input" id="desc-{{ $pid }}" name="description" rows="3">{{ $v('description') }}</textarea></div>
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="{{ $section }} mb-3">Pricing</legend>
                <div><label class="label" for="price-{{ $pid }}">Default price</label>
                    <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-500">$</span>
                        <input class="input pl-7 tabular-nums" id="price-{{ $pid }}" name="default_price" type="number" inputmode="decimal" step="0.0001" min="0" value="{{ $v('default_price') ?? 0 }}" required></div></div>
                <div><label class="label" for="unit-{{ $pid }}">Unit <span class="font-normal text-slate-500">(optional)</span></label><input class="input" id="unit-{{ $pid }}" name="unit" value="{{ $v('unit') }}" placeholder="hour, each, month"></div>
                <div><label class="label" for="cad-{{ $pid }}">Billing</label><select class="input" id="cad-{{ $pid }}" name="default_cadence"><option value="">One-time</option>@foreach (['week' => 'Weekly', 'month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Annual'] as $k => $label)<option value="{{ $k }}" @selected($v('default_cadence') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="label" for="tax-{{ $pid }}">Sales tax</label><select class="input" id="tax-{{ $pid }}" name="tax_rate_id"><option value="">Not taxable</option>@foreach ($taxRates->where('active', true) as $t)<option value="{{ $t->id }}" @selected($v('tax_rate_id') == $t->id)>{{ $t->name }} ({{ rtrim(rtrim((string) $t->rate, '0'), '.') }}%)</option>@endforeach</select></div>
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="{{ $section }} mb-3">Accounting</legend>
                <div><label class="label" for="inc-{{ $pid }}">Income account</label><select class="input" id="inc-{{ $pid }}" name="income_account_id">@foreach ($incomeAccounts as $a)<option value="{{ $a->id }}" @selected($v('income_account_id') == $a->id || (! $v('income_account_id') && $a->system_key === 'service_income'))>{{ $a->label() }}</option>@endforeach</select></div>
                <div><label class="label" for="cogs-{{ $pid }}">Cost of goods account <span class="font-normal text-slate-500">(optional)</span></label><select class="input" id="cogs-{{ $pid }}" name="cogs_account_id"><option value="">None</option>@foreach ($cogsAccounts as $a)<option value="{{ $a->id }}" @selected($v('cogs_account_id') == $a->id)>{{ $a->label() }}</option>@endforeach</select></div>
            </fieldset>
        </div>

        <div class="modal-foot">
            @if ($p->exists)
                <label class="mr-auto flex items-center gap-2 text-sm"><input type="hidden" name="active" value="0"><input class="check" type="checkbox" name="active" value="1" @checked(old('_dialog') === $dlg ? old('active') : $p->active)> Active <span class="text-slate-500">— available for new invoices</span></label>
            @endif
            <button type="button" class="btn" data-dialog-close>Cancel</button>
            <button class="btn btn-primary">{{ $p->exists ? 'Save changes' : 'Add item' }}</button>
        </div>
    </form>
</dialog>
