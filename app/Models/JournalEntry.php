<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date', 'created_at' => 'datetime'];

    public function lines(): HasMany { return $this->hasMany(JournalLine::class); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_of_id'); }

    public function reversedBy(): ?JournalEntry
    {
        return static::where('business_id', $this->business_id)->where('reversal_of_id', $this->id)->first();
    }
}
