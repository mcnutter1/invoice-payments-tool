<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractVersion extends Model
{
    use BelongsToBusiness;

    /** Version statuses an invoice may be linked to. */
    public const INVOICE_LINKABLE = ['completed', 'partially_signed'];

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function recipients(): HasMany { return $this->hasMany(ContractRecipient::class)->orderBy('sign_order')->orderBy('id'); }
    public function events(): HasMany { return $this->hasMany(SignatureEvent::class)->orderBy('seq'); }
    public function renderedDocument(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'rendered_document_id'); }
    public function sourceDocument(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'source_document_id'); }
    public function signedDocument(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'signed_document_id'); }
    public function certificateDocument(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'certificate_document_id'); }

    public function isLocked(): bool
    {
        return ! in_array($this->status, ['draft', 'prepared'], true);
    }
}
