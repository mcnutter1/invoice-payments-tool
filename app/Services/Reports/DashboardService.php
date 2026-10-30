<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;

/** Aggregates for the overview dashboard: monthly trends, aging, top clients, payment speed. */
class DashboardService
{
    /** @return array{labels:list<string>, collected:list<int>, invoiced:list<int>} last N months incl. current */
    public function monthlyTrend(Business $b, int $months = 12): array
    {
        $end = $b->localToday()->endOfMonth();
        $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $keys = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) $keys[$m->format('Y-m')] = $m->format('M');

        $collected = array_fill_keys(array_keys($keys), 0);
        Payment::where('business_id', $b->id)->where('status', '!=', 'reversed')
            ->whereDate('received_date', '>=', $start)->whereDate('received_date', '<=', $end)
            ->get(['received_date', 'amount_cents'])
            ->each(function ($p) use (&$collected) { $collected[$p->received_date->format('Y-m')] += (int) $p->amount_cents; });

        $invoiced = array_fill_keys(array_keys($keys), 0);
        Invoice::where('business_id', $b->id)->where('status', 'issued')
            ->whereDate('issue_date', '>=', $start)->whereDate('issue_date', '<=', $end)
            ->get(['issue_date', 'total_cents'])
            ->each(function ($i) use (&$invoiced) { $invoiced[$i->issue_date->format('Y-m')] += (int) $i->total_cents; });

        return ['labels' => array_values($keys), 'collected' => array_values($collected), 'invoiced' => array_values($invoiced)];
    }

    /** @return array{total:int, buckets:list<array{label:string,cents:int,tone:string}>} */
    public function aging(Business $b): array
    {
        $today = $b->localToday();
        $buckets = ['Current' => 0, '1–30 days' => 0, '31–60 days' => 0, '61–90 days' => 0, '90+ days' => 0];
        Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->get(['due_date', 'balance_cents'])
            ->each(function ($i) use (&$buckets, $today) {
                $late = $i->due_date ? (int) $i->due_date->diffInDays($today, false) : 0;
                $key = match (true) { $late <= 0 => 'Current', $late <= 30 => '1–30 days', $late <= 60 => '31–60 days', $late <= 90 => '61–90 days', default => '90+ days' };
                $buckets[$key] += (int) $i->balance_cents;
            });
        $tones = ['Current' => 'current', '1–30 days' => 'late1', '31–60 days' => 'late2', '61–90 days' => 'late3', '90+ days' => 'late4'];

        return [
            'total' => array_sum($buckets),
            'buckets' => array_map(fn ($k) => ['label' => $k, 'cents' => $buckets[$k], 'tone' => $tones[$k]], array_keys($buckets)),
        ];
    }

    /** @return list<array{name:string, id:int, cents:int}> clients by payments received in the last 12 months */
    public function topClients(Business $b, int $limit = 5): array
    {
        $since = $b->localToday()->subYear();

        return Payment::with('client:id,display_name')->where('business_id', $b->id)->where('status', '!=', 'reversed')
            ->whereDate('received_date', '>=', $since)
            ->selectRaw('client_id, SUM(amount_cents) as cents')->groupBy('client_id')->orderByDesc('cents')->limit($limit)->get()
            ->map(fn ($r) => ['id' => (int) $r->client_id, 'name' => $r->client?->display_name ?? '—', 'cents' => (int) $r->cents])->all();
    }

    /** Average days from invoice date to payment application over the last 12 months (amount-weighted). */
    public function averageDaysToPay(Business $b): ?int
    {
        $rows = PaymentAllocation::query()->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->where('payment_allocations.business_id', $b->id)->whereNotNull('payment_allocations.payment_id')
            ->where('payment_allocations.status', 'active')
            ->whereDate('payment_allocations.applied_date', '>=', $b->localToday()->subYear())
            ->get(['payment_allocations.applied_date', 'payment_allocations.amount_cents', 'invoices.issue_date']);
        $weight = 0;
        $sum = 0;
        foreach ($rows as $r) {
            if (! $r->issue_date) continue;
            $days = max(0, Carbon::parse($r->issue_date)->diffInDays(Carbon::parse($r->applied_date)));
            $sum += $days * $r->amount_cents;
            $weight += $r->amount_cents;
        }

        return $weight > 0 ? (int) round($sum / $weight) : null;
    }

    /** Same-length comparison window for a dashboard preset. @return array{0:string,1:string} */
    public static function previousRange(string $preset, string $from, string $to): array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        return match ($preset) {
            'this_month', 'last_month' => [$f->subMonthNoOverflow()->toDateString(), $t->subMonthNoOverflow()->toDateString()],
            'this_quarter' => [$f->subMonthsNoOverflow(3)->toDateString(), $t->subMonthsNoOverflow(3)->toDateString()],
            default => [$f->subYear()->toDateString(), $t->subYear()->toDateString()],
        };
    }
}
