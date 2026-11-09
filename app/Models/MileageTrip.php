<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MileageTrip extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['trip_date' => 'date', 'round_trip' => 'boolean', 'miles' => 'decimal:1'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function rate(): BelongsTo { return $this->belongsTo(MileageRate::class, 'mileage_rate_id'); }

    /** Miles actually reimbursed (doubled for round trips). */
    public function totalMiles(): float
    {
        return (float) $this->miles * ($this->round_trip ? 2 : 1);
    }
}
