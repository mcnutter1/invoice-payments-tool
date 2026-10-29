<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ContractMilestone extends Model
{
    use BelongsToBusiness;

    protected $table = 'contract_billing_milestones';

    protected $guarded = ['id'];

    protected $casts = ['due_date' => 'date'];
}
