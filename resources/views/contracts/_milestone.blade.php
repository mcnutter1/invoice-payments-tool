<tr data-line>
    <td>
        <input class="input input-sm" name="milestones[{{ $i }}][name]" value="{{ $m['name'] ?? '' }}" placeholder="e.g. Discovery complete" aria-label="Milestone name">
        <input class="input input-sm mt-1" name="milestones[{{ $i }}][description]" value="{{ $m['description'] ?? '' }}" placeholder="Description (optional, shown on the invoice)" aria-label="Milestone description" maxlength="1000">
    </td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="milestones[{{ $i }}][amount]" value="{{ $m['amount'] ?? '' }}" aria-label="Milestone amount"></td>
    <td><input class="input input-sm" type="date" name="milestones[{{ $i }}][due_date]" value="{{ $m['due_date'] ?? '' }}" aria-label="Milestone invoice date"></td>
    <td><button type="button" class="btn btn-ghost btn-sm" data-remove-line aria-label="Remove milestone">✕</button></td>
</tr>
