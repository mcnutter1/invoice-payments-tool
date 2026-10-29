<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractRecipient extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $hidden = ['otp_hash'];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'otp_last_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'signed_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function version(): BelongsTo { return $this->belongsTo(ContractVersion::class, 'contract_version_id'); }

    public function isSigner(): bool
    {
        return in_array($this->role, ['client_signer', 'countersigner'], true);
    }
}
