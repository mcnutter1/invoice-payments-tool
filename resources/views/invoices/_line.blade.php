<tr data-line>
    <td>
        <label class="sr-only" for="lp-{{ $i }}">Product</label>
        <select class="input input-sm mb-1" id="lp-{{ $i }}" name="lines[{{ $i }}][product_id]" data-product>
            <option value="">Ad hoc item</option>
            @foreach ($products as $p)<option value="{{ $p->id }}" @selected(($l['product_id'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach
        </select>
        <label class="sr-only" for="ln-{{ $i }}">Item name</label>
        <input class="input input-sm mb-1" id="ln-{{ $i }}" name="lines[{{ $i }}][name]" value="{{ $l['name'] ?? '' }}" placeholder="Item name" data-name>
        <label class="sr-only" for="ld-{{ $i }}">Description</label>
        <textarea class="input input-sm" id="ld-{{ $i }}" name="lines[{{ $i }}][description]" rows="1" placeholder="Description (optional)" data-desc>{{ $l['description'] ?? '' }}</textarea>
        <details class="mt-1 text-xs"><summary class="cursor-pointer text-slate-500">Income account</summary>
            <select class="input input-sm mt-1" name="lines[{{ $i }}][income_account_id]" aria-label="Income account" data-income>
                @foreach ($incomeAccounts as $a)<option value="{{ $a->id }}" @selected(($l['income_account_id'] ?? null) == $a->id || (empty($l['income_account_id']) && $a->system_key === 'service_income'))>{{ $a->label() }}</option>@endforeach
            </select>
        </details>
        @if (! empty($l['contract_milestone_id']))<input type="hidden" name="lines[{{ $i }}][contract_milestone_id]" value="{{ $l['contract_milestone_id'] }}">@endif
    </td>
    <td><label class="sr-only" for="lq-{{ $i }}">Quantity</label><input class="input input-sm" id="lq-{{ $i }}" name="lines[{{ $i }}][quantity]" type="number" step="0.0001" min="0" value="{{ $l['quantity'] ?? '1' }}" data-qty></td>
    <td><label class="sr-only" for="lr-{{ $i }}">Rate</label><input class="input input-sm" id="lr-{{ $i }}" name="lines[{{ $i }}][unit_price]" type="number" step="0.0001" min="0" value="{{ $l['unit_price'] ?? '' }}" data-price></td>
    <td><label class="sr-only" for="lt-{{ $i }}">Tax</label>
        <select class="input input-sm" id="lt-{{ $i }}" name="lines[{{ $i }}][tax_rate_id]" data-tax>
            <option value="">No tax</option>
            @foreach ($taxRates as $t)<option value="{{ $t->id }}" @selected(($l['tax_rate_id'] ?? null) == $t->id)>{{ $t->name }}</option>@endforeach
        </select>
    </td>
    <td class="num pt-3" data-amount>—</td>
    <td><button type="button" class="btn btn-ghost btn-sm" data-remove-line aria-label="Remove line">✕</button></td>
</tr>
