<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = [
        'expense_date' => 'date',
        'due_date' => 'date',
        'possible_duplicate' => 'boolean',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany { return $this->hasMany(ExpenseLine::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function paymentAccount(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'payment_account_id'); }
    public function vendorPayments(): HasMany { return $this->hasMany(VendorPayment::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(PrivateDocument::class, 'receipt_document_id'); }

    public function balanceCents(): int
    {
        return $this->total_cents - $this->paid_cents;
    }
}
