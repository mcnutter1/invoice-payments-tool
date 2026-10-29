<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\EmailOutbox;
use App\Models\PrivateDocument;
use App\Models\RecurringSchedule;
use App\Models\ScheduleRun;
use App\Services\Auth\AuthGateway;
use App\Services\Notifications\NotificationGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function automation(AuthGateway $auth, NotificationGateway $notify)
    {
        $b = $this->biz();
        $backup = null;
        $file = config('finance.backup.status_file');
        if ($file && is_readable($file)) {
            $backup = json_decode((string) file_get_contents($file), true);
        }

        return view('operations.automation', [
            'heartbeats' => DB::table('job_heartbeats')->get()->keyBy('name'),
            'schedules' => RecurringSchedule::with('client')->where('business_id', $b->id)->where('status', 'active')->orderBy('next_run_date')->limit(20)->get(),
            'reviewRuns' => ScheduleRun::with('schedule')->where('business_id', $b->id)->whereIn('status', ['needs_review', 'failed'])->get(),
            'outbox' => EmailOutbox::where('business_id', $b->id)->latest('id')->limit(30)->get(),
            'needsReview' => EmailOutbox::where('business_id', $b->id)->where('needs_review', true)->whereIn('status', ['failed', 'delivery_unknown'])->get(),
            'queued' => EmailOutbox::where('business_id', $b->id)->where('status', 'queued')->count(),
            'auth' => $auth->health(),
            'notify' => $notify->health(),
            'storageBytes' => (int) PrivateDocument::where('business_id', $b->id)->sum('size'),
            'backup' => $backup,
            'failures' => AuditEvent::where('business_id', $b->id)->where('action', 'schedule.run_failed')->latest('id')->limit(10)->get(),
        ]);
    }

    public function audit(Request $request)
    {
        $q = AuditEvent::with('actor')->where('business_id', $this->biz()->id);
        if ($a = $request->query('action')) $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%');

        return view('operations.audit', ['events' => $q->latest('id')->paginate(50)->withQueryString()]);
    }
}
