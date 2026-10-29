<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRun extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['period_date' => 'date'];

    public function schedule(): BelongsTo { return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
