<tr data-line>
    <td><select class="input input-sm" name="lines[{{ $i }}][account_id]" aria-label="Account"><option value="">—</option>@foreach ($accounts as $a)@unless(in_array($a->system_key, ['ar', 'ap'], true))<option value="{{ $a->id }}" @selected(($l['account_id'] ?? null) == $a->id)>{{ $a->label() }}</option>@endunless @endforeach</select></td>
    <td><input class="input input-sm" name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}" aria-label="Description"></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $i }}][debit]" value="{{ $l['debit'] ?? '' }}" aria-label="Debit" data-jd></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $i }}][credit]" value="{{ $l['credit'] ?? '' }}" aria-label="Credit" data-jc></td>
    <td><button type="button" class="btn btn-ghost btn-sm" data-remove-line aria-label="Remove line">✕</button></td>
</tr>
