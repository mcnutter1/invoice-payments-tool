<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Backups\BackupNotifier;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupSettings;
use Illuminate\Console\Command;

class RunBackups extends Command
{
    use Heartbeat;

    protected $signature = 'finance:backups {--business= : Only this business ULID} {--full : Force a full backup}';

    protected $description = 'Back up every enabled business to S3 (daily full, hourly deltas) and prune old backups.';

    public function handle(BackupSettings $settings, BackupRunner $runner, BackupNotifier $notifier): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('backups', function () use ($settings, $runner, $notifier) {
            if (! $settings->configured()) return ['skipped' => 'backup storage not configured'];
            $stats = ['full' => 0, 'delta' => 0, 'failed' => 0, 'pruned' => 0];
            $results = [];
            $q = Business::where('status', 'active')->where('backups_enabled', true);
            if ($ulid = $this->option('business')) $q->where('ulid', strtolower($ulid));
            foreach ($q->get() as $b) {
                $snap = $runner->run($b, 'schedule', $this->option('full') ? 'full' : null);
                $results[] = $snap;
                $snap->isComplete() ? $stats[$snap->type]++ : $stats['failed']++;
                $stats['pruned'] += $runner->prune($b);
            }
            $notifier->afterRun($results);
            if ($stats['failed']) throw new \RuntimeException($stats['failed'].' business backup(s) failed; see Application settings → Backups.');

            return $stats;
        });
    }
}
