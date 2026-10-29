@php
    $label = $i->statusLabel();
    $cls = match (true) {
        $i->status === 'draft', $i->status === 'void' => 'chip-gray',
        $i->settlement_status === 'paid' => 'chip-green',
        $i->isOverdue() => 'chip-red',
        $i->settlement_status === 'partial' => 'chip-amber',
        default => 'chip-blue',
    };
@endphp
<span class="chip {{ $cls }}">{{ $label }}</span>
