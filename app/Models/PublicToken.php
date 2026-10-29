<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicToken extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = [
        'allowed_actions' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function contractVersion(): BelongsTo { return $this->belongsTo(ContractVersion::class); }
    public function recipient(): BelongsTo { return $this->belongsTo(ContractRecipient::class, 'contract_recipient_id'); }

    public function isValid(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->allowed_actions ?? [], true);
    }
}
