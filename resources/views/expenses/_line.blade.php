<tr data-line>
    <td><select class="input input-sm" name="lines[{{ $i }}][account_id]" aria-label="Category">@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(($l['account_id'] ?? null) == $a->id)>{{ $a->label() }}</option>@endforeach</select></td>
    <td><input class="input input-sm" name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}" aria-label="Line description"></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $i }}][amount]" value="{{ $l['amount'] ?? '' }}" aria-label="Amount" data-expense-amount></td>
    <td class="text-center"><input class="check" type="checkbox" name="lines[{{ $i }}][billable]" value="1" @checked(! empty($l['billable'])) aria-label="Billable to client"></td>
    <td><button type="button" class="btn btn-ghost btn-sm" data-remove-line aria-label="Remove line">✕</button></td>
</tr>
