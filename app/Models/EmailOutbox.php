<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailOutbox extends Model
{
    use BelongsToBusiness;

    protected $table = 'email_outbox';

    protected $guarded = ['id'];

    protected $casts = [
        'available_at' => 'datetime',
        'accepted_at' => 'datetime',
        'purged_at' => 'datetime',
        'needs_review' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (EmailOutbox $o) {
            $o->ulid ??= strtolower((string) Str::ulid());
            $o->correlation_id ??= (string) Str::uuid();
            $o->available_at ??= now();
        });
    }

    public function attempts(): HasMany { return $this->hasMany(EmailAttempt::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => 'Queued',
            'sending' => 'Sending',
            'accepted' => 'Accepted by mail service',
            'delivered' => 'Delivered',
            'bounced' => 'Bounced',
            'failed' => 'Failed',
            'delivery_unknown' => 'Delivery unknown — review',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }
}
