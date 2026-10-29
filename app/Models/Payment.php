<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'ulid'];

    protected $casts = [
        'received_date' => 'date',
        'deposited_date' => 'date',
        'cleared_date' => 'date',
        'reversed_at' => 'datetime',
        'receipt_emailed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Payment $p) => $p->ulid ??= strtolower((string) Str::ulid()));
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    public function refunds(): HasMany { return $this->hasMany(Refund::class); }
    public function depositAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'deposit_account_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function claim(): BelongsTo { return $this->belongsTo(PaymentClaim::class, 'claim_id'); }

    public function appliedCents(): int
    {
        return (int) $this->allocations()->where('status', 'active')->sum('amount_cents');
    }

    public function refundedCents(): int
    {
        return (int) $this->refunds()->sum('amount_cents');
    }

    public function unappliedCents(): int
    {
        if ($this->status === 'reversed') {
            return 0;
        }

        return $this->amount_cents - $this->appliedCents() - $this->refundedCents();
    }
}
