<?php

namespace App\Services\Expenses;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\ChartAccount;
use App\Models\MileageRate;
use App\Models\MileageTrip;
use App\Services\Audit;
use App\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Business mileage at the IRS standard rate, each trip posted as an expense. */
class MileageService
{
    public function __construct(private readonly ExpenseService $expenses, private readonly Context $ctx) {}

    /** Expense category for mileage; created on first use. */
    public function account(Business $b): ChartAccount
    {
        return ChartAccount::where('business_id', $b->id)->where('system_key', 'mileage')->first()
            ?? ChartAccount::create(['business_id' => $b->id, 'code' => ChartAccount::where('business_id', $b->id)->where('code', '6955')->exists() ? '6956' : '6955',
                'name' => 'Vehicle Mileage (standard rate)', 'type' => 'expense', 'subtype' => 'operating', 'system_key' => 'mileage', 'is_active' => true]);
    }

    /** @return array{rate: ?MileageRate, millis: int, cents: int, miles: float, provisional: bool} */
    public function quote(Carbon|string $date, float $miles, bool $roundTrip): array
    {
        $rate = MileageRate::forDate($date);
        if (! $rate) throw new DomainException('No IRS mileage rate is known for '.Carbon::parse($date)->format('M j, Y').'.');
        $total = round($miles * ($roundTrip ? 2 : 1), 1);
        // tenths of a mile × tenths of a cent → cents, rounded half up
        $cents = (int) round(round($total * 10) * $rate->business_rate_millis / 100);

        return ['rate' => $rate, 'millis' => $rate->business_rate_millis, 'cents' => $cents, 'miles' => $total, 'provisional' => MileageRate::isProvisional($date, $rate)];
    }

    public function create(Business $b, array $data): MileageTrip
    {
        return DB::transaction(function () use ($b, $data) {
            $q = $this->quote($data['trip_date'], (float) $data['miles'], ! empty($data['round_trip']));
            if ($q['cents'] <= 0) throw new DomainException('Enter the miles driven.');
            $expense = $this->expenses->create($b, $this->expenseData($b, $data, $q));
            $trip = new MileageTrip($this->tripAttrs($data, $q) + ['expense_id' => $expense->id, 'created_by' => $this->ctx->user?->id]);
            $trip->business_id = $b->id;
            $trip->save();
            Audit::log($b, 'mileage.recorded', $trip, null, ['miles' => $q['miles'], 'rate_millis' => $q['millis'], 'amount_cents' => $q['cents']]);

            return $trip;
        });
    }

    public function update(MileageTrip $trip, array $data): MileageTrip
    {
        return DB::transaction(function () use ($trip, $data) {
            $b = Business::findOrFail($trip->business_id);
            $q = $this->quote($data['trip_date'], (float) $data['miles'], ! empty($data['round_trip']));
            if ($q['cents'] <= 0) throw new DomainException('Enter the miles driven.');
            if ($trip->expense) $this->expenses->update($trip->expense, $this->expenseData($b, $data, $q));
            $trip->update($this->tripAttrs($data, $q));
            Audit::log($b, 'mileage.updated', $trip, null, ['miles' => $q['miles'], 'rate_millis' => $q['millis'], 'amount_cents' => $q['cents']]);

            return $trip;
        });
    }

    /** Re-price a trip after the official rate for its date is published. */
    public function reprice(MileageTrip $trip): bool
    {
        $rate = MileageRate::forDate($trip->trip_date);
        if (! $rate || $rate->business_rate_millis === $trip->rate_millis || ! $trip->expense || $trip->expense->voided_at) return false;
        $this->update($trip, $trip->only(['trip_date', 'from_location', 'to_location', 'purpose', 'miles', 'round_trip', 'vehicle', 'client_id', 'project_id', 'notes'])
            + ['payment_account_id' => $trip->expense->payment_account_id, 'billable' => $trip->expense->lines()->where('billable', true)->exists()]);

        return true;
    }

    public function delete(MileageTrip $trip): void
    {
        DB::transaction(function () use ($trip) {
            if ($trip->expense && ! $trip->expense->voided_at) $this->expenses->void($trip->expense, 'Mileage trip deleted');
            Audit::log($trip->business_id, 'mileage.deleted', $trip, $trip->only(['trip_date', 'miles', 'purpose', 'amount_cents']));
            $trip->delete();
        });
    }

    private function tripAttrs(array $d, array $q): array
    {
        return [
            'trip_date' => Carbon::parse($d['trip_date'])->toDateString(), 'from_location' => $d['from_location'] ?? null, 'to_location' => $d['to_location'] ?? null,
            'purpose' => $d['purpose'], 'miles' => round((float) $d['miles'], 1), 'round_trip' => ! empty($d['round_trip']), 'vehicle' => $d['vehicle'] ?? null,
            'rate_millis' => $q['millis'], 'mileage_rate_id' => $q['rate']->id, 'amount_cents' => $q['cents'],
            'client_id' => ($d['client_id'] ?? null) ?: null, 'project_id' => ($d['project_id'] ?? null) ?: null, 'notes' => $d['notes'] ?? null,
        ];
    }

    private function expenseData(Business $b, array $d, array $q): array
    {
        $route = trim(implode(' → ', array_filter([$d['from_location'] ?? null, $d['to_location'] ?? null])));
        $rate = rtrim(rtrim(number_format($q['millis'] / 10, 1), '0'), '.').'¢/mi';

        return [
            'kind' => 'expense', 'source' => 'mileage', 'expense_date' => $d['trip_date'],
            'description' => 'Mileage: '.$d['purpose'], 'client_id' => ($d['client_id'] ?? null) ?: null, 'project_id' => ($d['project_id'] ?? null) ?: null,
            'payment_account_id' => $d['payment_account_id'] ?? $b->account('owner_contributions')->id,
            'notes' => $d['notes'] ?? null,
            'lines' => [['account_id' => $this->account($b)->id, 'amount_cents' => $q['cents'],
                'description' => trim($q['miles'].' mi'.($route ? ' · '.$route : '').($d['round_trip'] ?? false ? ' (round trip)' : '').' @ '.$rate.' IRS standard rate'), 'billable' => ! empty($d['billable'])]],
        ];
    }
}
