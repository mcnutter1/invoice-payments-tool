<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringSchedule extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'auto_issue' => 'boolean',
        'auto_send' => 'boolean',
        'require_signed_contract' => 'boolean',
        'recipients' => 'array',
        'payment_methods' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function lines(): HasMany { return $this->hasMany(RecurringLineItem::class)->orderBy('position'); }
    public function runs(): HasMany { return $this->hasMany(ScheduleRun::class)->orderByDesc('period_date'); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
}
