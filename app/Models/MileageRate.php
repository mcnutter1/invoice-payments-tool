<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** IRS standard mileage rate (business use), stored in tenths of a cent per mile (70¢ = 700). */
class MileageRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    /** The rate in effect on a date, or null if none is known yet for that date's period. */
    public static function forDate(Carbon|string $date): ?self
    {
        $d = Carbon::parse($date)->toDateString();

        return static::whereDate('effective_from', '<=', $d)->orderByDesc('effective_from')->first();
    }

    /** True when the newest known rate is from an earlier year than $date (the IRS may not have published it yet). */
    public static function isProvisional(Carbon|string $date, ?self $rate): bool
    {
        return ! $rate || $rate->effective_from->year < Carbon::parse($date)->year;
    }

    public function cents(): float
    {
        return $this->business_rate_millis / 10;
    }
}
