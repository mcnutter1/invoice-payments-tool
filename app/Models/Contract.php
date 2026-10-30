<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Contract extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'ulid'];

    protected $casts = [
        'effective_date' => 'date',
        'term_end_date' => 'date',
        'term_start_date' => 'date',
        'term_open_ended' => 'boolean',
        'billing_start_date' => 'date',
        'billing_auto' => 'boolean',
        'terminated_at' => 'datetime',
        'externally_signed' => 'boolean',
        'require_email_code' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Contract $c) => $c->ulid ??= strtolower((string) Str::ulid()));
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function versions(): HasMany { return $this->hasMany(ContractVersion::class)->orderByDesc('version'); }
    public function currentVersion(): BelongsTo { return $this->belongsTo(ContractVersion::class, 'current_version_id'); }
    public function milestones(): HasMany { return $this->hasMany(ContractMilestone::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function schedules(): HasMany { return $this->hasMany(RecurringSchedule::class); }

    /** Agreement is executed (in-app completed or externally signed with provenance). */
    public function isExecuted(): bool
    {
        return $this->status === 'completed' || ($this->externally_signed && $this->external_provenance);
    }

    public function isActiveForBilling(?\DateTimeInterface $on = null): bool
    {
        $on = $on ? \Illuminate\Support\Carbon::instance($on) : today();
        if (! $this->isExecuted() && ! $this->billing_override_reason) return false;
        if ($this->terminated_at) return false;
        if ($this->effective_date && $on->lt($this->effective_date)) return false;
        if ($this->term_start_date && $on->lt($this->term_start_date)) return false;
        if ($this->term_end_date && $on->gt($this->term_end_date)) return false;

        return true;
    }

    /** Human-readable term, e.g. "Jan 1, 2027 – Dec 31, 2027" or "Jan 1, 2027 – open-ended". */
    public function termLabel(string $format = 'M j, Y'): ?string
    {
        $start = $this->term_start_date ?? $this->effective_date;
        $end = $this->term_end_date?->format($format) ?? ($this->term_open_ended ? 'open-ended' : null);
        if (! $start && ! $end) return null;

        return ($start?->format($format) ?? 'Start not set').' – '.($end ?? 'end not set');
    }

    public function invoicedCents(): int
    {
        return (int) $this->invoices()->where('status', 'issued')->sum('total_cents');
    }

    public function collectedCents(): int
    {
        return (int) $this->invoices()->where('status', 'issued')->sum('paid_cents');
    }
}
