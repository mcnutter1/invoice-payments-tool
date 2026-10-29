<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'ulid'];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'service_start' => 'date',
        'service_end' => 'date',
        'payment_methods' => 'array',
        'recipients' => 'array',
        'client_snapshot' => 'array',
        'business_snapshot' => 'array',
        'instructions_snapshot' => 'array',
        'template_snapshot' => 'array',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Invoice $i) => $i->ulid ??= strtolower((string) Str::ulid()));
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function lines(): HasMany { return $this->hasMany(InvoiceLine::class)->orderBy('position'); }
    public function taxLines(): HasMany { return $this->hasMany(InvoiceTaxLine::class); }
    public function documentVersions(): HasMany { return $this->hasMany(InvoiceDocumentVersion::class)->orderByDesc('version'); }
    public function allocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    public function claims(): HasMany { return $this->hasMany(PaymentClaim::class); }
    public function creditNotes(): HasMany { return $this->hasMany(CreditNote::class); }
    public function outbox(): HasMany { return $this->hasMany(EmailOutbox::class); }
    public function accessEvents(): HasMany { return $this->hasMany(DocumentAccessEvent::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function contractVersion(): BelongsTo { return $this->belongsTo(ContractVersion::class); }
    public function schedule(): BelongsTo { return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function latestDocument(): ?InvoiceDocumentVersion
    {
        return $this->documentVersions()->first();
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isIssued(): bool { return $this->status === 'issued'; }

    public function isOverdue(): bool
    {
        return $this->isIssued() && $this->balance_cents > 0 && $this->due_date && $this->due_date->lt(today());
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Draft';
    }

    public function statusLabel(): string
    {
        if ($this->status === 'draft') return 'Draft';
        if ($this->status === 'void') return 'Void';
        return match ($this->settlement_status) {
            'paid' => 'Paid',
            'partial' => $this->isOverdue() ? 'Partially paid · overdue' : 'Partially paid',
            'written_off' => 'Written off',
            default => $this->isOverdue() ? 'Overdue' : 'Open',
        };
    }
}
