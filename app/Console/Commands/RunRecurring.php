<?php

namespace App\Console\Commands;

use App\Services\Recurring\RecurringService;
use Illuminate\Console\Command;

class RunRecurring extends Command
{
    use Heartbeat;

    protected $signature = 'finance:recurring';

    protected $description = 'Generate due recurring invoices (idempotent per billing period).';

    public function handle(RecurringService $service): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('recurring', fn () => $service->runDue(microtime(true) + config('finance.cron.max_seconds'), config('finance.cron.recurring_batch')));
    }
}
