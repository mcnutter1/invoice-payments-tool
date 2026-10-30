<tr data-line>
    <td class="min-w-[16rem]">
        <select class="input input-sm mb-1" name="lines[{{ $i }}][product_id]" aria-label="Service or product" data-product>
            <option value="">Custom item</option>
            @foreach ($products as $p)<option value="{{ $p->id }}" @selected(($l['product_id'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach
        </select>
        <input class="input input-sm mb-1" name="lines[{{ $i }}][name]" value="{{ $l['name'] ?? '' }}" placeholder="Item name" aria-label="Item name" data-name>
        <input class="input input-sm" name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}" placeholder="Description (optional)" aria-label="Description" data-desc>
    </td>
    <td class="w-24"><input class="input input-sm" type="number" step="0.0001" min="0" name="lines[{{ $i }}][quantity]" value="{{ $l['quantity'] ?? '1' }}" aria-label="Quantity" data-qty></td>
    <td class="w-32"><input class="input input-sm" type="number" step="0.0001" min="0" name="lines[{{ $i }}][unit_price]" value="{{ $l['unit_price'] ?? '' }}" aria-label="Rate" data-price></td>
    <td class="w-40"><select class="input input-sm" name="lines[{{ $i }}][tax_rate_id]" aria-label="Tax" data-tax><option value="">No tax</option>@foreach ($taxRates as $t)<option value="{{ $t->id }}" @selected(($l['tax_rate_id'] ?? null) == $t->id)>{{ $t->name }}</option>@endforeach</select></td>
    <td class="num w-28 pt-3 font-medium" data-amount>—</td>
    <td class="w-10"><button type="button" class="btn btn-ghost btn-sm" data-remove-line aria-label="Remove item">✕</button></td>
</tr>
