<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A client engagement, trip or job that expenses and mileage are grouped under. */
class Project extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function expenses(): HasMany { return $this->hasMany(Expense::class); }
    public function trips(): HasMany { return $this->hasMany(MileageTrip::class); }

    public function label(): string
    {
        return $this->name.($this->client ? ' · '.$this->client->display_name : '');
    }
}
