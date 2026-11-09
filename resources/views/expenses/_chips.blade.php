@if ($e->source === 'import')<span class="chip chip-blue" title="{{ $e->import_meta['statement'] ?? 'Imported from CSV' }}">Imported</span>@elseif ($e->source === 'mileage')<span class="chip chip-green">Mileage</span>@elseif ($e->source === 'migration')<span class="chip chip-gray">Migrated</span>@endif
@if ($e->edited_at)<span class="chip chip-gray" title="Edited {{ $e->edited_at->diffForHumans() }}">Edited</span>@endif
@if ($e->possible_duplicate && empty($noDup))<span class="chip chip-amber">Possible duplicate</span>@endif
@if ($e->receipt_document_id)<span class="chip chip-gray">Receipt</span>@endif
@if ($e->voided_at)<span class="chip chip-red">Voided</span>@endif
