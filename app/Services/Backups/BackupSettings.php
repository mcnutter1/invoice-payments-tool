<?php

namespace App\Services\Backups;

use App\Models\PlatformSetting;

/** Application-level backup configuration (S3 target, retention, defaults), set by SSO admins. */
class BackupSettings
{
    public const DEFAULTS = [
        'bucket' => '', 'region' => 'us-east-1', 'prefix' => 'mcnutt-finance', 'access_key_id' => '',
        'endpoint' => '', 'path_style' => false, 'default_enabled' => true,
        'delta_retention_days' => 7, 'full_retention_days' => 35,
        'class_delta' => 'STANDARD', 'class_full' => 'GLACIER_IR', 'class_files' => 'GLACIER_IR',
        'notify_failures' => true, 'notify_weekly' => true, 'notify_emails' => '',
    ];

    /**
     * Storage classes that can be read back immediately (the explorer, previews and restores need instant reads).
     * Prices are approximate us-east-1 USD per GB-month, for estimates only.
     */
    public const STORAGE_CLASSES = [
        'STANDARD' => ['label' => 'S3 Standard', 'price' => 0.023, 'min_days' => 0, 'note' => 'No minimums. Best for small, short-lived hourly deltas.'],
        'INTELLIGENT_TIERING' => ['label' => 'S3 Intelligent-Tiering', 'price' => 0.023, 'min_days' => 0, 'note' => 'Starts at Standard price, drops to ~$0.0125 after 30 idle days and ~$0.004 after 90; small per-object monitoring fee.'],
        'STANDARD_IA' => ['label' => 'S3 Standard-IA', 'price' => 0.0125, 'min_days' => 30, 'note' => '30-day minimum, 128 KB minimum billable size.'],
        'GLACIER_IR' => ['label' => 'S3 Glacier Instant Retrieval', 'price' => 0.004, 'min_days' => 90, 'note' => 'Lowest-cost class with millisecond reads. 90-day minimum, per-GB retrieval fee.'],
    ];

    public static function regions(): array
    {
        return ['us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'ca-west-1', 'sa-east-1', 'mx-central-1',
            'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-central-2', 'eu-north-1', 'eu-south-1', 'eu-south-2',
            'ap-south-1', 'ap-south-2', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3', 'ap-southeast-4', 'ap-southeast-5',
            'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-east-1', 'me-south-1', 'me-central-1', 'il-central-1', 'af-south-1',
            'us-gov-east-1', 'us-gov-west-1'];
    }

    /** @return list<string> */
    public function notifyRecipients(): array
    {
        $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->all()['notify_emails']) ?: []));
        if (! $list) {
            $list = \App\Models\User::whereNotNull('email')->get()->filter->isPlatformAdmin()->pluck('email')->all();
        }

        return array_values(array_unique(array_filter(array_map('strtolower', $list), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    public function all(): array
    {
        return array_merge(self::DEFAULTS, (array) PlatformSetting::read('backups', []));
    }

    public function save(array $values, ?string $secret): void
    {
        PlatformSetting::write('backups', array_intersect_key($values, self::DEFAULTS) + $this->all());
        if ($secret !== null && $secret !== '') PlatformSetting::writeSecret('backups.secret', $secret);
    }

    public function configured(): bool
    {
        if (app()->bound(BackupStore::class)) return true;
        $c = $this->all();

        return $c['bucket'] !== '' && $c['access_key_id'] !== '' && PlatformSetting::read('backups.secret') !== null;
    }

    public function hasSecret(): bool
    {
        return PlatformSetting::read('backups.secret') !== null;
    }

    public function prefix(): string
    {
        return trim((string) $this->all()['prefix'], '/');
    }

    /** The configured store; tests and local development may bind their own BackupStore. */
    public function store(): BackupStore
    {
        if (app()->bound(BackupStore::class)) return app(BackupStore::class);
        $c = $this->all();

        return new S3Store([
            'bucket' => $c['bucket'], 'region' => $c['region'], 'key' => $c['access_key_id'],
            'secret' => (string) PlatformSetting::readSecret('backups.secret'),
            'endpoint' => $c['endpoint'] ?: null, 'path_style' => (bool) $c['path_style'],
        ]);
    }
}
