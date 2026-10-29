<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected $casts = ['tax_exempt' => 'boolean'];

    public function contacts(): HasMany { return $this->hasMany(Contact::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function contracts(): HasMany { return $this->hasMany(Contract::class); }
    public function schedules(): HasMany { return $this->hasMany(RecurringSchedule::class); }

    public function billingEmail(): ?string
    {
        $primary = $this->contacts->firstWhere('is_primary_billing', true);

        return $primary?->email ?: $this->email;
    }

    public function billingAddressLines(): array
    {
        return array_values(array_filter([
            $this->billing_line1,
            $this->billing_line2,
            trim(implode(' ', array_filter([$this->billing_city ? $this->billing_city.',' : null, $this->billing_state, $this->billing_postal_code]))),
        ]));
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'legal_name' => $this->legal_name,
            'email' => $this->billingEmail(),
            'phone' => $this->phone,
            'address' => $this->billingAddressLines(),
            'tax_exempt' => $this->tax_exempt,
        ];
    }
}
