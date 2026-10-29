<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\EmailOutbox;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use App\Models\RecurringSchedule;
use App\Models\ScheduleRun;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, ReportService $reports)
    {
        $b = $this->biz();
        $ctx = $this->ctx();
        [$from, $to, $preset] = $this->dateRange($request);
        $metrics = $ctx->can('reports.pnl.view') ? $reports->dashboard($b, $from, $to) : null;
        $ar = $ctx->canAny('reports.ar.view', 'invoices.view') ? [
            'outstanding' => (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->sum('balance_cents'),
            'overdue' => (int) Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->whereDate('due_date', '<', $b->localToday())->sum('balance_cents'),
            'overdue_count' => Invoice::where('business_id', $b->id)->where('status', 'issued')->where('balance_cents', '>', 0)->whereDate('due_date', '<', $b->localToday())->count(),
        ] : null;

        return view('dashboard', [
            'from' => $from, 'to' => $to, 'preset' => $preset,
            'metrics' => $metrics,
            'ar' => $ar,
            'claims' => $ctx->can('payments.view') ? PaymentClaim::with('invoice.client')->where('business_id', $b->id)->whereIn('status', ['reported', 'clarification_requested'])->latest()->limit(8)->get() : collect(),
            'awaitingSignature' => $ctx->can('contracts.view') ? Contract::with('client')->where('business_id', $b->id)->whereIn('status', ['sent', 'partially_signed'])->latest()->limit(8)->get() : collect(),
            'dueSoon' => $ctx->can('recurring.view') ? RecurringSchedule::with('client')->where('business_id', $b->id)->where('status', 'active')->whereDate('next_run_date', '<=', $b->localToday()->addDays(14))->orderBy('next_run_date')->limit(8)->get() : collect(),
            'reviewRuns' => $ctx->can('recurring.view') ? ScheduleRun::where('business_id', $b->id)->where('status', 'needs_review')->count() : 0,
            'deliveryIssues' => $ctx->can('invoices.view') ? EmailOutbox::where('business_id', $b->id)->where('needs_review', true)->whereIn('status', ['failed', 'delivery_unknown'])->count() : 0,
            'recentInvoices' => $ctx->can('invoices.view') ? Invoice::with('client')->where('business_id', $b->id)->latest('updated_at')->limit(6)->get() : collect(),
        ]);
    }

    public function search(Request $request)
    {
        $b = $this->biz();
        $q = trim((string) $request->query('q'));
        $results = [];
        if (mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            if ($this->ctx()->can('clients.view')) {
                foreach (Client::where('business_id', $b->id)->where(fn ($w) => $w->where('display_name', 'like', $like)->orWhere('email', 'like', $like))->limit(8)->get() as $c) {
                    $results[] = ['type' => 'Client', 'label' => $c->display_name, 'url' => "/b/{$b->ulid}/clients/{$c->id}"];
                }
            }
            if ($this->ctx()->can('invoices.view')) {
                foreach (Invoice::with('client')->where('business_id', $b->id)->where('number', 'like', $like)->limit(8)->get() as $i) {
                    $results[] = ['type' => 'Invoice', 'label' => $i->number.' · '.$i->client->display_name, 'url' => "/b/{$b->ulid}/invoices/{$i->id}"];
                }
            }
            if ($this->ctx()->can('contracts.view')) {
                foreach (Contract::where('business_id', $b->id)->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('title', 'like', $like))->limit(5)->get() as $c) {
                    $results[] = ['type' => 'Contract', 'label' => $c->number.' · '.$c->title, 'url' => "/b/{$b->ulid}/contracts/{$c->id}"];
                }
            }
        }

        return view('search', ['q' => $q, 'results' => $results]);
    }
}
