<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date'];

    public function entry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function account(): BelongsTo { return $this->belongsTo(ChartAccount::class, 'account_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }

    public function signedAmount(): int
    {
        return $this->debit_cents - $this->credit_cents;
    }
}
