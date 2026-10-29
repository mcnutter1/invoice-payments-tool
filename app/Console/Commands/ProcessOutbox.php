<?php

namespace App\Console\Commands;

use App\Services\Notifications\OutboxProcessor;
use Illuminate\Console\Command;

class ProcessOutbox extends Command
{
    use Heartbeat;

    protected $signature = 'finance:outbox {--limit=}';

    protected $description = 'Send queued emails through the notification service (bounded run).';

    public function handle(OutboxProcessor $processor): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('outbox', fn () => $processor->run(
            (int) ($this->option('limit') ?: config('finance.cron.outbox_batch')),
            microtime(true) + config('finance.cron.max_seconds')
        ));
    }
}
