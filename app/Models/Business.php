<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    protected $guarded = ['id', 'ulid'];

    protected $casts = [
        'payment_methods' => 'array',
        'branding' => 'array',
        'reminder_settings' => 'array',
        'show_tax_id' => 'boolean',
        'backups_enabled' => 'boolean',
        'w9_settings' => 'array',
        'books_closed_through' => 'date',
        'payment_instructions_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Business $b) {
            $b->ulid ??= strtolower((string) \Illuminate\Support\Str::ulid());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function memberships(): HasMany { return $this->hasMany(Membership::class); }
    public function roles(): HasMany { return $this->hasMany(Role::class); }
    public function clients(): HasMany { return $this->hasMany(Client::class); }
    public function contacts(): HasMany { return $this->hasMany(Contact::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function vendors(): HasMany { return $this->hasMany(Vendor::class); }
    public function taxRates(): HasMany { return $this->hasMany(TaxRate::class); }
    public function accounts(): HasMany { return $this->hasMany(ChartAccount::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function creditNotes(): HasMany { return $this->hasMany(CreditNote::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function claims(): HasMany { return $this->hasMany(PaymentClaim::class); }
    public function expenses(): HasMany { return $this->hasMany(Expense::class); }
    public function bankAccounts(): HasMany { return $this->hasMany(BankAccount::class); }
    public function bankTransactions(): HasMany { return $this->hasMany(BankTransaction::class); }
    public function importBatches(): HasMany { return $this->hasMany(ImportBatch::class); }
    public function reconciliations(): HasMany { return $this->hasMany(ReconciliationSession::class); }
    public function journalEntries(): HasMany { return $this->hasMany(JournalEntry::class); }
    public function contracts(): HasMany { return $this->hasMany(Contract::class); }
    public function contractTemplates(): HasMany { return $this->hasMany(ContractTemplate::class); }
    public function schedules(): HasMany { return $this->hasMany(RecurringSchedule::class); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }
    public function outbox(): HasMany { return $this->hasMany(EmailOutbox::class); }
    public function documents(): HasMany { return $this->hasMany(PrivateDocument::class); }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(PrivateDocument::class, 'logo_document_id');
    }

    public function name(): string
    {
        return $this->display_name ?: $this->legal_name;
    }

    public function account(string $systemKey): ChartAccount
    {
        return $this->accounts()->where('system_key', $systemKey)->firstOrFail();
    }

    public function brand(string $key, mixed $default = null): mixed
    {
        $defaults = [
            'template' => 'classic',
            'accent' => '#1f5f8b',
            'font' => 'DejaVu Sans',
            'header_text' => '',
            'footer_text' => 'Thank you for your business.',
            'show_sku' => false,
            'show_quantity' => true,
            'due_date_prominent' => true,
            'email_intro' => 'Please find your invoice below. You can view it online and download a PDF copy.',
        ];

        return ($this->branding ?? [])[$key] ?? ($defaults[$key] ?? $default);
    }

    public function allowedMethods(): array
    {
        return $this->payment_methods ?: ['zelle', 'check'];
    }

    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->city ? $this->city.',' : null, $this->state, $this->postal_code]))),
        ]));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function localToday(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse(now($this->timezone ?: 'America/New_York')->toDateString());
    }

    public const REMINDERS = [
        'contract_unsigned' => 'Agreements awaiting signature',
        'invoice_unviewed' => 'Invoices sent but not yet viewed',
        'invoice_overdue' => 'Past-due invoices',
    ];

    /** @return array{enabled: bool, every_days: int} */
    public function reminder(string $kind): array
    {
        $cfg = $this->reminder_settings ?? [];
        $s = (array) ($cfg[$kind] ?? []) + [
            // Legacy single flag only ever covered overdue invoices.
            'enabled' => $kind === 'invoice_overdue' && ! empty($cfg['enabled']),
            'every_days' => 3,
        ];

        return ['enabled' => (bool) $s['enabled'], 'every_days' => max(1, min(90, (int) $s['every_days']))];
    }
}
