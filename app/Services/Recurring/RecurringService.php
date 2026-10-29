<?php

namespace App\Services\Recurring;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringSchedule;
use App\Models\ScheduleRun;
use App\Services\Audit;
use App\Services\Invoicing\InvoiceService;
use App\Services\Notifications\EmailComposer;
use App\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringService
{
    public function __construct(
        private readonly RecurrenceCalculator $calc,
        private readonly InvoiceService $invoices,
        private readonly EmailComposer $email,
        private readonly Context $ctx,
    ) {}

    public function save(Business $business, ?RecurringSchedule $schedule, array $data): RecurringSchedule
    {
        return DB::transaction(function () use ($business, $schedule, $data) {
            $client = \App\Models\Client::where('business_id', $business->id)->findOrFail($data['client_id']);
            $contract = null;
            $versionId = null;
            if (! empty($data['contract_id'])) {
                $contract = Contract::where('business_id', $business->id)->findOrFail($data['contract_id']);
                if ($contract->client_id !== $client->id) throw new DomainException('Agreement belongs to a different client.');
                $versionId = $contract->current_version_id;
            }
            $attrs = [
                'client_id' => $client->id,
                'contract_id' => $contract?->id,
                'contract_version_id' => $versionId,
                'name' => $data['name'],
                'cadence' => $data['cadence'],
                'interval' => max(1, (int) ($data['interval'] ?? 1)),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'anchor_day' => $data['anchor_day'] ?? null,
                'timezone' => $business->timezone,
                'terms_days' => (int) ($data['terms_days'] ?? $business->default_terms_days),
                'auto_issue' => ! empty($data['auto_issue']),
                'auto_send' => ! empty($data['auto_send']) && ! empty($data['auto_issue']),
                'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['recipients'] ?? ''))))),
                'payment_methods' => $data['payment_methods'] ?? $business->allowedMethods(),
                'customer_note' => $data['customer_note'] ?? null,
                'price_policy' => ($data['price_policy'] ?? null) === 'catalog' ? 'catalog' : 'snapshot',
                'require_signed_contract' => $contract ? ! empty($data['require_signed_contract'] ?? true) : false,
            ];
            if ($schedule) {
                $before = $schedule->only(array_keys($attrs));
                $schedule->fill($attrs);
                $schedule->save();
                $schedule->lines()->delete();
                [$b, $a] = Audit::diff($before, $schedule->only(array_keys($attrs)));
                Audit::log($business, 'schedule.updated', $schedule, $b, $a, $data['change_reason'] ?? null);
            } else {
                $schedule = new RecurringSchedule($attrs + ['status' => 'active', 'created_by' => $this->ctx->user?->id]);
                $schedule->business_id = $business->id;
                $schedule->save();
                Audit::log($business, 'schedule.created', $schedule);
            }
            foreach (array_values($data['lines'] ?? []) as $i => $l) {
                if (trim((string) ($l['name'] ?? '')) === '') continue;
                $product = ! empty($l['product_id']) ? Product::where('business_id', $business->id)->find($l['product_id']) : null;
                $schedule->lines()->create([
                    'business_id' => $business->id,
                    'position' => $i,
                    'product_id' => $product?->id,
                    'name' => $l['name'],
                    'description' => $l['description'] ?? null,
                    'quantity' => $l['quantity'] ?? '1',
                    'unit_price' => $l['unit_price'] ?? ($product?->default_price ?? '0'),
                    'income_account_id' => $product?->income_account_id ?? $business->account('service_income')->id,
                    'tax_rate_id' => ($l['tax_rate_id'] ?? null) ?: null,
                ]);
            }
            if ($schedule->lines()->count() === 0) throw new DomainException('Add at least one line.');
            // Effective period: next run is the first occurrence on/after today (or start), never retroactive.
            $from = Carbon::parse(max($schedule->start_date->toDateString(), $business->localToday()->toDateString()));
            $last = $schedule->runs()->first();
            if ($last) $from = Carbon::parse(max($from->toDateString(), $last->period_date->copy()->addDay()->toDateString()));
            $schedule->next_run_date = $this->calc->firstOnOrAfter($schedule, $from)?->toDateString();
            $schedule->save();

            return $schedule->fresh('lines');
        });
    }

    public function setStatus(RecurringSchedule $s, string $status, ?string $reason = null): void
    {
        if (! in_array($status, ['active', 'paused', 'cancelled'], true)) throw new DomainException('Invalid status.');
        if ($s->status === 'cancelled') throw new DomainException('Cancelled schedules cannot change.');
        $old = $s->status;
        $s->status = $status;
        if ($status === 'active') {
            $business = Business::find($s->business_id);
            // Resume from the next period on/after today; missed periods while paused are not generated.
            $s->next_run_date = $this->calc->firstOnOrAfter($s, Carbon::parse(max($s->start_date->toDateString(), $business->localToday()->toDateString())))?->toDateString();
        }
        $s->save();
        Audit::log($s->business_id, 'schedule.'.$status, $s, ['status' => $old], ['status' => $status], $reason);
    }

    /** Process due schedules; safe to run concurrently. */
    public function runDue(float $deadline, int $limit = 50): array
    {
        $stats = ['generated' => 0, 'review' => 0, 'paused' => 0, 'errors' => 0];
        $ids = RecurringSchedule::where('status', 'active')->whereNotNull('next_run_date')
            ->whereDate('next_run_date', '<=', now()->addDay()->toDateString())
            ->orderBy('next_run_date')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) > $deadline) break;
            try {
                $r = $this->runOne($id);
                foreach ($r as $k => $v) $stats[$k] += $v;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('recurring.failed', ['schedule' => $id, 'error' => $e->getMessage()]);
                Audit::log(RecurringSchedule::find($id)?->business_id, 'schedule.run_failed', RecurringSchedule::find($id), null, ['error' => mb_substr($e->getMessage(), 0, 300)], null, 'system');
            }
        }

        return $stats;
    }

    public function runOne(int $id): array
    {
        $stats = ['generated' => 0, 'review' => 0, 'paused' => 0];
        $created = [];
        DB::transaction(function () use ($id, &$stats, &$created) {
            $s = RecurringSchedule::lockForUpdate()->find($id);
            if (! $s || $s->status !== 'active' || ! $s->next_run_date) return;
            $business = Business::find($s->business_id);
            if (! $business || ! $business->isActive()) return;
            $today = Carbon::parse(now($s->timezone ?: $business->timezone)->toDateString());
            $due = [];
            $d = Carbon::parse($s->next_run_date->toDateString());
            while ($d && $d->lte($today) && count($due) < 400) {
                if ($s->end_date && $d->gt($s->end_date)) break;
                $due[] = $d;
                $d = $this->calc->nextAfter($s, $d);
            }
            if (! $due) return;

            if ($s->contract_id && $s->require_signed_contract) {
                $contract = Contract::find($s->contract_id);
                if (! $contract || ! $contract->isActiveForBilling(end($due))) {
                    $s->status = 'paused';
                    $s->save();
                    Audit::log($business, 'schedule.paused_by_agreement', $s, null, ['contract_status' => $contract?->status], 'Agreement is not executed, has ended, or was terminated', 'system');
                    $stats['paused']++;

                    return;
                }
            }

            $latest = array_pop($due);
            foreach ($due as $missed) {
                $inserted = ScheduleRun::insertOrIgnore([
                    'business_id' => $s->business_id,
                    'recurring_schedule_id' => $s->id,
                    'billing_period_key' => RecurrenceCalculator::periodKey($missed),
                    'period_date' => $missed->toDateString(),
                    'status' => 'needs_review',
                    'error' => 'Missed while automation was not running; review before generating.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['review'] += $inserted;
            }
            $invoice = $this->generate($s, $latest, $business, true);
            if ($invoice) {
                $stats['generated']++;
                $created[] = $invoice;
            }
            $next = $this->calc->nextAfter($s, $latest);
            $s->next_run_date = $next?->toDateString();
            if (! $next) $s->status = 'ended';
            $s->last_run_at = now();
            $s->save();
        });

        return $stats;
    }

    private function generate(RecurringSchedule $s, Carbon $period, Business $business, bool $automatic): ?Invoice
    {
        $key = RecurrenceCalculator::periodKey($period);
        $run = ScheduleRun::where('business_id', $s->business_id)->where('recurring_schedule_id', $s->id)->where('billing_period_key', $key)->first();
        if ($run && ($run->status === 'generated' || ($automatic && $run->status !== 'needs_review'))) {
            return null;
        }
        if (! $run) {
            $run = new ScheduleRun(['recurring_schedule_id' => $s->id, 'billing_period_key' => $key, 'period_date' => $period->toDateString(), 'status' => 'generating']);
            $run->business_id = $s->business_id;
            $run->save(); // unique key blocks a concurrent duplicate
        }
        $s->loadMissing('lines');
        $next = $this->calc->nextAfter($s, $period);
        $invoice = $this->invoices->saveDraft($business, null, [
            'client_id' => $s->client_id,
            'issue_date' => $period->toDateString(),
            'due_date' => $period->copy()->addDays($s->terms_days)->toDateString(),
            'service_start' => $period->toDateString(),
            'service_end' => ($next ? $next->copy()->subDay() : $period)->toDateString(),
            'contract_version_id' => $s->contract_version_id,
            'customer_note' => $s->customer_note,
            'payment_methods' => $s->payment_methods,
            'recipients' => $s->recipients ?: null,
            'recurring_schedule_id' => $s->id,
            'lines' => $s->lines->map(function ($l) use ($s, $business) {
                $price = (string) $l->unit_price;
                if ($s->price_policy === 'catalog' && $l->product_id) {
                    $price = (string) (Product::where('business_id', $business->id)->find($l->product_id)?->default_price ?? $price);
                }

                return ['product_id' => $l->product_id, 'name' => $l->name, 'description' => $l->description, 'quantity' => (string) $l->quantity,
                    'unit_price' => $price, 'income_account_id' => $l->income_account_id, 'tax_rate_id' => $l->tax_rate_id];
            })->all(),
        ]);
        if ($s->auto_issue && $automatic) {
            $invoice = $this->invoices->issue($invoice);
            if ($s->auto_send) {
                $this->email->queueInvoice($invoice, $invoice->recipients ?? []);
            }
        }
        $run->update(['status' => 'generated', 'invoice_id' => $invoice->id, 'error' => null]);
        Audit::log($business, 'schedule.generated', $s, null, ['period' => $key, 'invoice_id' => $invoice->id, 'auto_issue' => $s->auto_issue, 'auto_send' => $s->auto_send], null, $automatic ? 'system' : null);

        return $invoice;
    }

    /** Staff-reviewed generation of a missed period: always creates a draft for review. */
    public function generateReviewed(ScheduleRun $run): Invoice
    {
        return DB::transaction(function () use ($run) {
            $run = ScheduleRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'needs_review') throw new DomainException('This period was already handled.');
            $s = RecurringSchedule::findOrFail($run->recurring_schedule_id);

            return $this->generate($s, Carbon::parse($run->period_date->toDateString()), Business::findOrFail($s->business_id), false);
        });
    }

    public function skipRun(ScheduleRun $run, string $reason): void
    {
        if ($run->status !== 'needs_review') throw new DomainException('This period was already handled.');
        $run->update(['status' => 'skipped', 'error' => $reason]);
        Audit::log($run->business_id, 'schedule.period_skipped', $run, null, null, $reason);
    }
}
