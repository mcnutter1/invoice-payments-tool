<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['posted_date' => 'date', 'matched_at' => 'datetime'];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function matchedLine(): BelongsTo { return $this->belongsTo(JournalLine::class, 'matched_journal_line_id'); }
    public function batch(): BelongsTo { return $this->belongsTo(ImportBatch::class, 'import_batch_id'); }
}
