<?php

namespace App\Services\Backups;

use App\Models\PlatformSetting;
use Illuminate\Support\Str;
use Throwable;

/** Step-by-step check of the S3 setup, with plain-language fixes. The result is saved for the settings page. */
class BackupDiagnostics
{
    public function __construct(private readonly BackupSettings $settings, private readonly BackupVerifier $verifier) {}

    /** @return array{ok:bool, at:string, steps:list<array{name:string, ok:?bool, detail:string}>} */
    public function run(): array
    {
        $c = $this->settings->all();
        $steps = [];
        $add = function (string $name, ?bool $ok, string $detail) use (&$steps) {
            $steps[] = compact('name', 'ok', 'detail');

            return $ok !== false;
        };

        $complete = $this->settings->configured();
        $add('Settings complete', $complete, $complete ? 's3://'.$c['bucket'].'/'.$this->settings->prefix().'/ in '.$c['region'] : 'Enter the bucket, region, access key and secret, then save.');
        if (! $complete) return $this->finish($steps);
        if (! $c['endpoint'] && ! in_array($c['region'], BackupSettings::regions(), true)) {
            $add('Region', false, '“'.$c['region'].'” is not an AWS region code (for example us-east-1).');
        }

        $store = $this->settings->store();
        $key = $this->settings->prefix().'/_connection-test/'.Str::ulid();
        $probe = random_bytes(64);
        $classes = array_values(array_unique([$c['class_delta'], $c['class_full'], $c['class_files']]));
        $written = [];
        foreach ($classes as $class) {
            try {
                $store->put($key.'-'.strtolower($class).'.bin', $probe, $class);
                $written[] = $key.'-'.strtolower($class).'.bin';
                $sse = $store instanceof S3Store ? ($store->lastHeaders['x-amz-server-side-encryption'] ?? null) : 'AES256';
                $add('Write ('.(BackupSettings::STORAGE_CLASSES[$class]['label'] ?? $class).')', true, 'Upload accepted'.($sse ? ', encrypted at rest ('.$sse.')' : ''));
                if ($store instanceof S3Store && ! $sse) {
                    $add('Encryption at rest', false, 'S3 did not confirm server-side encryption. Enable default encryption (SSE-S3) on the bucket.');
                }
            } catch (Throwable $e) {
                $add('Write ('.(BackupSettings::STORAGE_CLASSES[$class]['label'] ?? $class).')', false, $e->getMessage());

                return $this->finish($steps, $store, $written);
            }
        }
        try {
            $ok = $store->get($written[0]) === $probe;
            $add('Read back', $ok, $ok ? 'Downloaded instantly and matched byte-for-byte.' : 'The object read back did not match what was written.');
        } catch (Throwable $e) {
            $add('Read back', false, $e->getMessage());
        }
        try {
            foreach ($written as $k) $store->delete($k);
            $written = [];
            $add('Delete', true, 'Test objects removed (needed for retention clean-up).');
        } catch (Throwable $e) {
            $add('Delete', false, $e->getMessage().' Without s3:DeleteObject, old backups can’t be pruned.');
        }

        $latest = \App\Models\BackupSnapshot::where('status', 'completed')->latest('id')->first();
        if ($latest) {
            $ok = $this->verifier->verify($latest);
            $add('Restore test (latest backup)', $ok, $ok
                ? 'Downloaded, checksum-verified, decrypted and rebuilt the backup of '.$latest->business?->name().' from '.$latest->started_at->diffForHumans().'.'
                : (string) $latest->fresh()->verify_error);
        } else {
            $add('Restore test', null, 'No backups yet — run one to test a full restore.');
        }

        return $this->finish($steps, $store, $written);
    }

    private function finish(array $steps, ?BackupStore $store = null, array $leftovers = []): array
    {
        foreach ($leftovers as $k) {
            try {
                $store?->delete($k);
            } catch (Throwable) {
            }
        }
        $result = ['ok' => ! in_array(false, array_column($steps, 'ok'), true), 'at' => now()->toIso8601String(), 'steps' => $steps];
        PlatformSetting::write('backups.last_test', $result);

        return $result;
    }
}
