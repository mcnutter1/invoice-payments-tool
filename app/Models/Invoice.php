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
        'auto_reminders' => 'boolean',
    ];

    /** Per-invoice override, else on when either invoice reminder type is enabled for the business. */
    public function autoRemindersOn(Business $b): bool
    {
        return $this->auto_reminders ?? ($b->reminder('invoice_unviewed')['enabled'] || $b->reminder('invoice_overdue')['enabled']);
    }

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

    /** Who this invoice was actually emailed to, else its saved recipients, else the client's billing email. */
    public function deliveryRecipients(): array
    {
        $sent = EmailOutbox::where('business_id', $this->business_id)->where('invoice_id', $this->id)
            ->whereIn('kind', ['invoice', 'reminder'])->whereNotIn('status', ['cancelled', 'failed'])
            ->distinct()->pluck('to_email')->all();

        return array_values(array_unique(array_map('strtolower', $sent ?: ($this->recipients ?: array_filter([$this->client?->billingEmail()])))));
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
        if ($this->status === 'void') return 'Cancelled';
        return match ($this->settlement_status) {
            'paid' => 'Paid',
            'partial' => $this->isOverdue() ? 'Partially paid · overdue' : 'Partially paid',
            'written_off' => 'Written off',
            default => $this->isOverdue() ? 'Overdue' : 'Open',
        };
    }
}
