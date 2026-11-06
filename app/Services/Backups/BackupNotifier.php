<?php

namespace App\Services\Backups;

use App\Models\BackupFile;
use App\Models\BackupSnapshot;
use App\Models\Business;
use App\Models\PlatformSetting;
use App\Services\Notifications\NotificationGateway;
use App\Services\Notifications\OutgoingMessage;
use Illuminate\Support\Str;

/** Emails administrators about backup failures, recoveries and a weekly summary. */
class BackupNotifier
{
    /** Repeat an alert for a still-failing business at most this often. */
    private const REALERT_HOURS = 24;

    public function __construct(private readonly BackupSettings $settings, private readonly NotificationGateway $gateway) {}

    /** @param iterable<BackupSnapshot> $results this run's snapshots */
    public function afterRun(iterable $results): void
    {
        $state = (array) PlatformSetting::read('backups.alerts', []);
        $failed = $recovered = [];
        foreach ($results as $s) {
            $bid = (string) $s->business_id;
            if ($s->status === 'failed') {
                $last = isset($state[$bid]) ? strtotime($state[$bid]) : null;
                if (! $last || $last < now()->subHours(self::REALERT_HOURS)->getTimestamp()) {
                    $failed[] = $s;
                    $state[$bid] = now()->toIso8601String();
                }
            } elseif (isset($state[$bid])) {
                $recovered[] = $s;
                unset($state[$bid]);
            }
        }
        PlatformSetting::write('backups.alerts', $state);
        if (! $this->settings->all()['notify_failures']) return;

        if ($failed) {
            $this->send('Backup failed for '.count($failed).' business'.(count($failed) === 1 ? '' : 'es'), [
                'tag' => 'Backup alert', 'heading' => 'Backups need attention',
                'paragraphs' => ['The latest backup run failed for the businesses below. The previous backups are still intact. Check the settings and run “Test connection” in Application settings.'],
                'details' => array_map(fn ($s) => [$this->name($s->business_id), Str::limit((string) $s->error, 160)], $failed),
                'buttons' => [['label' => 'Open backup settings', 'url' => url('/platform/backups')]],
            ]);
        }
        if ($recovered) {
            $this->send('Backups recovered for '.count($recovered).' business'.(count($recovered) === 1 ? '' : 'es'), [
                'tag' => 'Backup alert', 'heading' => 'Backups are working again',
                'details' => array_map(fn ($s) => [$this->name($s->business_id), ucfirst($s->type).' backup completed '.$s->finished_at?->format('M j, g:ia T')], $recovered),
            ]);
        }
    }

    public function alert(string $subject, string $text): void
    {
        if ($this->settings->all()['notify_failures']) {
            $this->send($subject, ['tag' => 'Backup alert', 'heading' => $subject, 'paragraphs' => [$text], 'buttons' => [['label' => 'Open backup settings', 'url' => url('/platform/backups')]]]);
        }
    }

    /** @param array<int,array{business:Business, ok:bool, error:?string}> $verified */
    public function weekly(array $verified): void
    {
        if (! $this->settings->all()['notify_weekly']) return;
        $since = now()->subWeek();
        $snaps = BackupSnapshot::where('started_at', '>=', $since)->get(['business_id', 'status', 'type', 'bytes', 'created_count', 'updated_count', 'deleted_count']);
        $rows = [];
        foreach (Business::where('backups_enabled', true)->orderBy('display_name')->get() as $b) {
            $mine = $snaps->where('business_id', $b->id);
            $v = $verified[$b->id] ?? null;
            $rows[] = [$b->name(), sprintf('%d ok · %d failed · %d changes · restore test %s',
                $mine->where('status', 'completed')->count(), $mine->where('status', 'failed')->count(),
                $mine->sum(fn ($s) => $s->created_count + $s->updated_count + $s->deleted_count),
                $v === null ? 'not run' : ($v['ok'] ? 'passed' : 'FAILED'))];
        }
        $bytes = (int) BackupSnapshot::sum('bytes') + (int) BackupFile::sum('size');
        $failedVerify = array_filter($verified, fn ($v) => ! $v['ok']);
        $this->send('Weekly backup summary'.($failedVerify ? ' — restore test failed' : ''), [
            'tag' => 'Weekly summary', 'heading' => 'Backups, week of '.$since->format('M j'),
            'paragraphs' => array_values(array_filter([
                ! $this->settings->configured() ? 'Backup storage is not configured, so nothing is being backed up.' : null,
                $failedVerify ? 'A restore test failed for '.implode(', ', array_map(fn ($v) => $v['business']->name().' ('.Str::limit((string) $v['error'], 120).')', $failedVerify)).'.' : 'Each business’s latest backup was downloaded, checksum-verified, decrypted and rebuilt successfully.',
            ])),
            'highlight' => ['label' => 'Stored in S3', 'value' => BackupSnapshot::humanBytes($bytes),
                'sub' => $snaps->where('status', 'completed')->count().' backups completed, '.$snaps->where('status', 'failed')->count().' failed this week'],
            'details' => $rows,
            'buttons' => [['label' => 'Open backup settings', 'url' => url('/platform/backups')]],
        ]);
    }

    private function name(int $businessId): string
    {
        return Business::find($businessId)?->name() ?? 'Business #'.$businessId;
    }

    private function send(string $subject, array $email): void
    {
        $from = 'McNutt Finance <'.config('finance.notifications.default_from_email').'>';
        $html = view('emails.message', $email + ['subject' => $subject, 'brand' => ['name' => 'McNutt Finance', 'accent' => '#1f5f8b', 'logo' => null, 'contact' => '', 'address' => ''],
            'footer' => 'You receive this because you are an administrator of McNutt Finance. Change who gets these in Application settings → Backups.'])->render();
        foreach ($this->settings->notifyRecipients() as $to) {
            try {
                $this->gateway->send(new OutgoingMessage($to, $from, $subject, $html, 'backup-'.Str::lower((string) Str::ulid())));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
