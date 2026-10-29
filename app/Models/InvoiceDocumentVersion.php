<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDocumentVersion extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function document(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'document_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
