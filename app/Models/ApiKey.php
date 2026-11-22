<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Read-only Data API credential for one business (e.g. McNutt Legacy). Only the SHA-256 of the token is stored. */
class ApiKey extends Model
{
    public const SCOPES = [
        'ledger.read' => ['Ledger', 'Chart of accounts, journal entries and cash-basis events'],
        'invoices.read' => ['Invoicing', 'Clients, invoices and credit notes / write-offs'],
        'payments.read' => ['Payments', 'Payments, fees and how they were applied'],
        'expenses.read' => ['Expenses', 'Vendors, expenses and bills'],
        'reports.read' => ['Reports', 'Profit & loss, balance sheet, trial balance and the tax estimate'],
    ];

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** @return array{0: ApiKey, 1: string} the saved key and the plain token (shown once). */
    public static function issue(Business $b, string $name, array $scopes, ?\DateTimeInterface $expires, ?int $userId): array
    {
        $token = 'fin_'.Str::random(48);
        $key = self::create([
            'business_id' => $b->id, 'name' => $name, 'token_hash' => hash('sha256', $token), 'prefix' => substr($token, 0, 12),
            'scopes' => array_values(array_intersect(array_keys(self::SCOPES), $scopes)), 'expires_at' => $expires, 'created_by' => $userId,
        ]);

        return [$key, $token];
    }

    public static function findActive(string $token): ?self
    {
        if (! str_starts_with($token, 'fin_') || strlen($token) > 100) return null;
        $key = self::with('business')->where('token_hash', hash('sha256', $token))->first();

        return $key && $key->isActive() && $key->business?->status === 'active' ? $key : null;
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
