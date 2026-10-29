<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Product;
use App\Models\RecurringSchedule;
use App\Models\ScheduleRun;
use App\Models\TaxRate;
use App\Services\Recurring\RecurrenceCalculator;
use App\Services\Recurring\RecurringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecurringController extends Controller
{
    public function __construct(private readonly RecurringService $service, private readonly RecurrenceCalculator $calc) {}

    private function schedule(int $id): RecurringSchedule
    {
        return RecurringSchedule::where('business_id', $this->biz()->id)->findOrFail($id);
    }

    public function index()
    {
        $b = $this->biz();
        $schedules = RecurringSchedule::with('client', 'lines', 'contract')->where('business_id', $b->id)->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->orderBy('next_run_date')->get();

        return view('recurring.index', [
            'schedules' => $schedules,
            'amounts' => $schedules->mapWithKeys(fn ($s) => [$s->id => $this->calc->scheduleAmountCents($s)]),
            'review' => ScheduleRun::with('schedule.client')->where('business_id', $b->id)->where('status', 'needs_review')->orderBy('period_date')->get(),
        ]);
    }

    private function formData(?RecurringSchedule $s): array
    {
        $b = $this->biz();

        return [
            'schedule' => $s,
            'clients' => Client::where('business_id', $b->id)->where('status', 'active')->orderBy('display_name')->get(['id', 'display_name']),
            'contracts' => Contract::where('business_id', $b->id)->whereNotIn('status', ['voided'])->get(['id', 'number', 'title', 'client_id', 'status']),
            'products' => Product::where('business_id', $b->id)->where('active', true)->orderBy('name')->get(),
            'taxRates' => TaxRate::where('business_id', $b->id)->where('active', true)->get(),
        ];
    }

    public function create()
    {
        return view('recurring.form', $this->formData(null));
    }

    private function validated(Request $request): array
    {
        $b = $this->biz();

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['required', Rule::exists('clients', 'id')->where('business_id', $b->id)],
            'contract_id' => ['nullable', 'integer'],
            'cadence' => ['required', Rule::in(['week', 'month', 'quarter', 'year'])],
            'interval' => ['required', 'integer', 'min:1', 'max:24'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'anchor_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_issue' => ['nullable', 'boolean'],
            'auto_send' => ['nullable', 'boolean'],
            'recipients' => ['nullable', 'string', 'max:1000'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => [Rule::in(['zelle', 'check'])],
            'customer_note' => ['nullable', 'string', 'max:5000'],
            'price_policy' => ['nullable', Rule::in(['snapshot', 'catalog'])],
            'require_signed_contract' => ['nullable', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:200'],
            'lines.*.description' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['require_signed_contract'] = $request->boolean('require_signed_contract', true);
        $s = $this->service->save($this->biz(), null, $data);

        return $this->to('/recurring/'.$s->id, 'Recurring schedule created.');
    }

    public function show(int $id)
    {
        $s = $this->schedule($id)->load('client', 'lines', 'contract', 'runs.invoice');

        return view('recurring.show', [
            'schedule' => $s,
            'upcoming' => $this->calc->upcoming($s, 3),
            'amount' => $this->calc->scheduleAmountCents($s),
            'activity' => \App\Models\AuditEvent::with('actor')->where('business_id', $s->business_id)->where('object_type', 'RecurringSchedule')->where('object_id', $s->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function edit(int $id)
    {
        return view('recurring.form', $this->formData($this->schedule($id)->load('lines')));
    }

    public function update(Request $request, int $id)
    {
        $s = $this->schedule($id);
        $data = $this->validated($request);
        $data['require_signed_contract'] = $request->boolean('require_signed_contract', true);
        $this->service->save($this->biz(), $s, $data);

        return $this->to('/recurring/'.$id, 'Schedule updated. Changes apply to future periods only.');
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'cancelled'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $this->service->setStatus($this->schedule($id), $data['status'], $data['reason'] ?? null);

        return back()->with('status', 'Schedule '.$data['status'].'.');
    }

    public function generateRun(int $id)
    {
        $run = ScheduleRun::where('business_id', $this->biz()->id)->findOrFail($id);
        $invoice = $this->service->generateReviewed($run);

        return $this->to('/invoices/'.$invoice->id.'/edit', 'Draft created for the missed period. Review, issue and send when ready.');
    }

    public function skipRun(Request $request, int $id)
    {
        $run = ScheduleRun::where('business_id', $this->biz()->id)->findOrFail($id);
        $this->service->skipRun($run, $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);

        return back()->with('status', 'Period skipped.');
    }
}
