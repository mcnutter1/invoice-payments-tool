<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Backups\BackupNotifier;
use App\Services\Backups\BackupSettings;
use App\Services\Backups\BackupVerifier;
use Illuminate\Console\Command;

class BackupsWeekly extends Command
{
    use Heartbeat;

    protected $signature = 'finance:backups-weekly';

    protected $description = 'Restore-test the latest backup of every business and email administrators a weekly summary.';

    public function handle(BackupSettings $settings, BackupVerifier $verifier, BackupNotifier $notifier): int
    {
        app(\App\Support\Context::class)->actorType = 'system';

        return $this->beat('backups-weekly', function () use ($settings, $verifier, $notifier) {
            $verified = [];
            if ($settings->configured()) {
                foreach (Business::where('backups_enabled', true)->get() as $b) {
                    $latest = $verifier->latest($b->id);
                    if (! $latest) continue;
                    $ok = $verifier->verify($latest);
                    $verified[$b->id] = ['business' => $b, 'ok' => $ok, 'error' => $latest->fresh()->verify_error];
                }
            }
            $notifier->weekly($verified);

            return ['verified' => count($verified), 'failed' => count(array_filter($verified, fn ($v) => ! $v['ok']))];
        });
    }
}
