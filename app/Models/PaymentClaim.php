<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentClaim extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['reported_date' => 'date', 'reviewed_at' => 'datetime'];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function attachment(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'attachment_document_id'); }

    public function isOpen(): bool
    {
        return in_array($this->status, ['reported', 'clarification_requested'], true);
    }
}
