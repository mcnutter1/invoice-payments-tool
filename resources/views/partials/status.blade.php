@php
    $map = [
        'draft' => ['Draft', 'chip-gray'], 'prepared' => ['Ready to send', 'chip-blue'], 'sent' => ['Sent', 'chip-blue'],
        'partially_signed' => ['Partially signed', 'chip-amber'], 'completed' => ['Completed', 'chip-green'], 'declined' => ['Declined', 'chip-red'],
        'expired' => ['Expired', 'chip-gray'], 'voided' => ['Voided', 'chip-gray'], 'void' => ['Void', 'chip-gray'],
        'active' => ['Active', 'chip-green'], 'paused' => ['Paused', 'chip-amber'], 'cancelled' => ['Cancelled', 'chip-gray'], 'ended' => ['Ended', 'chip-gray'],
        'recorded' => ['Recorded', 'chip-blue'], 'deposited' => ['Deposited', 'chip-green'], 'cleared' => ['Cleared', 'chip-green'], 'reversed' => ['Reversed', 'chip-red'],
        'reported' => ['Awaiting confirmation', 'chip-amber'], 'confirmed' => ['Confirmed', 'chip-green'], 'rejected' => ['Rejected', 'chip-red'],
        'clarification_requested' => ['Needs clarification', 'chip-amber'], 'paid' => ['Paid', 'chip-green'], 'unpaid' => ['Unpaid', 'chip-amber'],
        'partial' => ['Partially paid', 'chip-amber'], 'unmatched' => ['Unmatched', 'chip-amber'], 'matched' => ['Matched', 'chip-green'],
        'posted' => ['Posted', 'chip-green'], 'excluded' => ['Excluded', 'chip-gray'], 'pending' => ['Pending', 'chip-gray'], 'viewed' => ['Viewed', 'chip-blue'],
        'verified' => ['Verified', 'chip-blue'], 'signed' => ['Signed', 'chip-green'], 'in_progress' => ['In progress', 'chip-amber'],
        'needs_review' => ['Needs review', 'chip-amber'], 'generated' => ['Generated', 'chip-green'], 'skipped' => ['Skipped', 'chip-gray'],
        'failed' => ['Failed', 'chip-red'], 'queued' => ['Queued', 'chip-gray'], 'accepted' => ['Accepted by mail service', 'chip-blue'],
        'delivery_unknown' => ['Delivery unknown', 'chip-red'], 'undone' => ['Undone', 'chip-gray'], 'invoiced' => ['Invoiced', 'chip-green'], 'scheduled' => ['Invoice scheduled', 'chip-blue'],
        'pending_approval' => ['Awaiting approval', 'chip-amber'], 'archived' => ['Archived', 'chip-gray'],
    ];
    [$label, $cls] = $map[$s] ?? [ucfirst(str_replace('_', ' ', (string) $s)), 'chip-gray'];
@endphp
<span class="chip {{ $cls }}">{{ $label }}</span>
