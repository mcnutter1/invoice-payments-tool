<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MileageRate;
use App\Models\MileageTrip;
use App\Models\Project;
use App\Services\Expenses\ExpenseService;
use App\Services\Expenses\MileageService;
use Illuminate\Http\Request;

class MileageController extends Controller
{
    public function __construct(private readonly MileageService $service) {}

    private function trip(int $id): MileageTrip
    {
        return MileageTrip::with('expense')->where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $b = $this->biz();
        [$from, $to] = [$request->query('from', $b->localToday()->startOfYear()->toDateString()), $request->query('to', $b->localToday()->toDateString())];
        $q = MileageTrip::with('client', 'project', 'expense')->where('business_id', $b->id)->whereBetween('trip_date', [$from, $to]);
        if ($c = $request->query('client')) $q->where('client_id', (int) $c);
        if ($p = $request->query('project')) $q->where('project_id', (int) $p);
        $trips = $q->orderByDesc('trip_date')->orderByDesc('id')->get();
        $latest = MileageRate::orderByDesc('effective_from')->first();
        $provisional = $trips->filter(fn ($t) => ($r = MileageRate::forDate($t->trip_date)) && $r->business_rate_millis !== $t->rate_millis)->count();

        return view('mileage.index', [
            'trips' => $trips, 'from' => $from, 'to' => $to, 'latest' => $latest, 'needsReprice' => $provisional,
            'currentProvisional' => MileageRate::isProvisional($b->localToday(), $latest),
            'clients' => Client::where('business_id', $b->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::where('business_id', $b->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function formData(?MileageTrip $t = null): array
    {
        $b = $this->biz();

        return [
            'trip' => $t,
            'clients' => Client::where('business_id', $b->id)->where(fn ($q) => $q->where('status', 'active')->orWhere('id', $t?->client_id))->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::with('client')->where('business_id', $b->id)->where(fn ($q) => $q->where('status', 'open')->orWhere('id', $t?->project_id))->orderBy('name')->get(),
            'funding' => ExpenseService::fundingAccounts($b),
            'rates' => MileageRate::orderBy('effective_from')->get()->map(fn ($r) => ['from' => $r->effective_from->toDateString(), 'millis' => $r->business_rate_millis])->values(),
            'vehicles' => MileageTrip::where('business_id', $b->id)->whereNotNull('vehicle')->distinct()->orderBy('vehicle')->pluck('vehicle'),
            'ownerAccount' => $b->account('owner_contributions')->id,
        ];
    }

    public function create(Request $request)
    {
        return view('mileage.form', $this->formData() + ['preset' => ['client_id' => $request->query('client'), 'project_id' => $request->query('project')]]);
    }

    public function edit(int $id)
    {
        return view('mileage.form', $this->formData($this->trip($id)) + ['preset' => []]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'trip_date' => ['required', 'date'],
            'from_location' => ['nullable', 'string', 'max:200'],
            'to_location' => ['nullable', 'string', 'max:200'],
            'purpose' => ['required', 'string', 'max:255'],
            'miles' => ['required', 'numeric', 'min:0.1', 'max:5000'],
            'round_trip' => ['nullable', 'boolean'],
            'vehicle' => ['nullable', 'string', 'max:80'],
            'client_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'payment_account_id' => ['required', 'integer'],
            'billable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    public function store(Request $request)
    {
        $trip = $this->service->create($this->biz(), $this->validated($request));

        return $this->to('/mileage', 'Trip recorded: '.$trip->totalMiles().' mi = $'.number_format($trip->amount_cents / 100, 2).'.');
    }

    public function update(Request $request, int $id)
    {
        $this->service->update($this->trip($id), $this->validated($request));

        return $this->to('/mileage', 'Trip updated.');
    }

    public function destroy(int $id)
    {
        $this->service->delete($this->trip($id));

        return $this->to('/mileage', 'Trip deleted and its expense voided.');
    }

    /** Re-price trips whose recorded rate differs from the (now published) IRS rate for their date. */
    public function reprice()
    {
        $n = $skipped = 0;
        foreach (MileageTrip::with('expense')->where('business_id', $this->biz()->id)->get() as $t) {
            try {
                $n += (int) $this->service->reprice($t);
            } catch (\App\Exceptions\DomainException) {
                $skipped++;
            }
        }

        return back()->with('status', ($n ? $n.' trip'.($n === 1 ? '' : 's').' re-priced at the current IRS rate.' : 'All trips already use the correct IRS rate.')
            .($skipped ? ' '.$skipped.' could not be changed (closed period or billed).' : ''));
    }
}
