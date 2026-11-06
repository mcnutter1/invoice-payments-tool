<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\BackupFile;
use App\Models\BackupSnapshot;
use App\Models\Business;
use App\Services\Audit;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Application-level backup settings and analytics. SSO admins only; shows backup metadata, never business data. */
class PlatformBackupController extends Controller
{
    public function __construct(private readonly BackupSettings $settings) {}

    private function authorizeAdmin(): void
    {
        abort_unless($this->ctx()->user?->isPlatformAdmin(), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();
        $snaps = BackupSnapshot::query();
        $perBusiness = BackupSnapshot::select('business_id', DB::raw('count(*) as snapshots'), DB::raw('sum(bytes) as bytes'), DB::raw('max(started_at) as last_at'))
            ->groupBy('business_id')->get()->keyBy('business_id');
        $latest = BackupSnapshot::whereIn('id', BackupSnapshot::selectRaw('max(id)')->groupBy('business_id'))->get()->keyBy('business_id');
        $lastFull = BackupSnapshot::whereIn('id', BackupSnapshot::selectRaw('max(id)')->where('type', 'full')->where('status', 'completed')->groupBy('business_id'))->get()->keyBy('business_id');
        $files = BackupFile::select('business_id', DB::raw('count(*) as files'), DB::raw('sum(size) as bytes'))->groupBy('business_id')->get()->keyBy('business_id');
        $cfg = $this->settings->all();
        $byClass = [];
        foreach (BackupSnapshot::select('storage_class', DB::raw('sum(bytes) as b'))->groupBy('storage_class')->get() as $r) $byClass[$r->storage_class ?: 'STANDARD'] = ($byClass[$r->storage_class ?: 'STANDARD'] ?? 0) + (int) $r->b;
        foreach (BackupFile::select('storage_class', DB::raw('sum(size) as b'))->groupBy('storage_class')->get() as $r) $byClass[$r->storage_class ?: 'STANDARD'] = ($byClass[$r->storage_class ?: 'STANDARD'] ?? 0) + (int) $r->b;
        $monthly = array_sum(array_map(fn ($cls, $b) => $b / 1073741824 * (BackupSettings::STORAGE_CLASSES[$cls]['price'] ?? 0.023), array_keys($byClass), $byClass));

        return view('platform.backups', [
            'cfg' => $cfg,
            'policies' => $this->policies($cfg),
            'lastTest' => \App\Models\PlatformSetting::read('backups.last_test'),
            'recipients' => $this->settings->notifyRecipients(),
            'byClass' => $byClass, 'monthly' => $monthly,
            'verified' => BackupSnapshot::whereIn('id', BackupSnapshot::selectRaw('max(id)')->whereNotNull('verified_at')->groupBy('business_id'))->get()->keyBy('business_id'),
            'hasSecret' => $this->settings->hasSecret(),
            'configured' => $this->settings->configured(),
            'businesses' => Business::orderBy('display_name')->get(),
            'perBusiness' => $perBusiness, 'latest' => $latest, 'lastFull' => $lastFull, 'files' => $files,
            'totals' => [
                'snapshots' => (clone $snaps)->where('status', 'completed')->count(),
                'bytes' => (int) (clone $snaps)->sum('bytes') + (int) BackupFile::sum('size'),
                'ok24' => (clone $snaps)->where('status', 'completed')->where('started_at', '>', now()->subDay())->count(),
                'failed24' => (clone $snaps)->where('status', 'failed')->where('started_at', '>', now()->subDay())->count(),
                'enabled' => Business::where('backups_enabled', true)->count(),
            ],
            'failures' => BackupSnapshot::with('business')->where('status', 'failed')->latest('id')->limit(10)->get(),
            'heartbeat' => DB::table('job_heartbeats')->where('name', 'backups')->first(),
        ]);
    }

    /** AWS JSON documents scoped to the configured bucket and prefix, for copy-paste setup. */
    private function policies(array $cfg): array
    {
        $bucket = $cfg['bucket'] ?: 'YOUR-BUCKET';
        $prefix = $this->settings->prefix() ?: 'mcnutt-finance';
        $json = fn ($a) => json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'iam' => $json(['Version' => '2012-10-17', 'Statement' => [
                ['Sid' => 'McNuttFinanceBackupObjects', 'Effect' => 'Allow', 'Action' => ['s3:PutObject', 's3:GetObject', 's3:DeleteObject'],
                    'Resource' => "arn:aws:s3:::{$bucket}/{$prefix}/*"],
                ['Sid' => 'McNuttFinanceBackupList', 'Effect' => 'Allow', 'Action' => 's3:ListBucket', 'Resource' => "arn:aws:s3:::{$bucket}",
                    'Condition' => ['StringLike' => ['s3:prefix' => ["{$prefix}/*"]]]],
            ]]),
            'bucket' => $json(['Version' => '2012-10-17', 'Statement' => [
                ['Sid' => 'DenyInsecureTransport', 'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:*',
                    'Resource' => ["arn:aws:s3:::{$bucket}", "arn:aws:s3:::{$bucket}/*"], 'Condition' => ['Bool' => ['aws:SecureTransport' => 'false']]],
            ]]),
            'lifecycle' => $json(['Rules' => [
                ['ID' => 'abort-incomplete-uploads', 'Status' => 'Enabled', 'Filter' => ['Prefix' => "{$prefix}/"], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1]],
                ['ID' => 'remove-connection-tests', 'Status' => 'Enabled', 'Filter' => ['Prefix' => "{$prefix}/_connection-test/"], 'Expiration' => ['Days' => 1]],
            ]]),
            'cli' => implode("\n", [
                "aws s3api create-bucket --bucket {$bucket} --region {$cfg['region']}".($cfg['region'] !== 'us-east-1' ? " --create-bucket-configuration LocationConstraint={$cfg['region']}" : ''),
                "aws s3api put-public-access-block --bucket {$bucket} --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true",
                "aws s3api put-bucket-encryption --bucket {$bucket} --server-side-encryption-configuration '{\"Rules\":[{\"ApplyServerSideEncryptionByDefault\":{\"SSEAlgorithm\":\"AES256\"}}]}'",
                "aws s3api put-bucket-policy --bucket {$bucket} --policy file://bucket-policy.json",
                "aws s3api put-bucket-lifecycle-configuration --bucket {$bucket} --lifecycle-configuration file://lifecycle.json",
                'aws iam create-user --user-name mcnutt-finance-backups',
                'aws iam put-user-policy --user-name mcnutt-finance-backups --policy-name mcnutt-finance-backups --policy-document file://iam-policy.json',
                'aws iam create-access-key --user-name mcnutt-finance-backups',
            ]),
        ];
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeAdmin();
        $classes = array_keys(BackupSettings::STORAGE_CLASSES);
        $data = $request->validate([
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', 'not_regex:/\.\.|^\d+\.\d+\.\d+\.\d+$|^xn--|-s3alias$/'],
            'region' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', Rule::when(! $request->filled('endpoint'), [Rule::in(BackupSettings::regions())])],
            'prefix' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'access_key_id' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Z0-9]+$/'],
            'secret_access_key' => [$this->settings->hasSecret() ? 'nullable' : 'required', 'string', 'min:20', 'max:256'],
            'endpoint' => ['nullable', 'url:https', 'max:200'],
            'path_style' => ['nullable', 'boolean'],
            'default_enabled' => ['nullable', 'boolean'],
            'delta_retention_days' => ['required', 'integer', 'min:1', 'max:90'],
            'full_retention_days' => ['required', 'integer', 'min:1', 'max:3650', 'gte:delta_retention_days'],
            'class_delta' => ['required', Rule::in($classes)],
            'class_full' => ['required', Rule::in($classes)],
            'class_files' => ['required', Rule::in($classes)],
            'notify_failures' => ['nullable', 'boolean'],
            'notify_weekly' => ['nullable', 'boolean'],
            'notify_emails' => ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) {
                foreach (array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $value) ?: [])) as $e) {
                    if (! filter_var($e, FILTER_VALIDATE_EMAIL)) $fail('“'.$e.'” is not a valid email address.');
                }
            }],
        ], ['region.in' => 'Use an AWS region code such as us-east-1 (or set a custom endpoint for S3-compatible storage).',
            'access_key_id.regex' => 'AWS access key IDs are upper-case letters and digits (e.g. AKIA…).',
            'full_retention_days.gte' => 'Keep daily fulls at least as long as hourly deltas.']);
        $values = ['prefix' => trim((string) ($data['prefix'] ?? ''), '/') ?: 'mcnutt-finance', 'endpoint' => $data['endpoint'] ?? '',
            'path_style' => $request->boolean('path_style'), 'default_enabled' => $request->boolean('default_enabled'),
            'notify_failures' => $request->boolean('notify_failures'), 'notify_weekly' => $request->boolean('notify_weekly'),
            'notify_emails' => (string) ($data['notify_emails'] ?? '')] + $data;
        $this->settings->save($values, $data['secret_access_key'] ?? null);
        Audit::log(null, 'platform.backup_settings_saved', null, null, collect($values)->except('secret_access_key')->all() + ['secret_changed' => ! empty($data['secret_access_key'])]);

        return back()->with('status', 'Backup settings saved.');
    }

    public function test(\App\Services\Backups\BackupDiagnostics $diagnostics)
    {
        $this->authorizeAdmin();
        $r = $diagnostics->run();
        Audit::log(null, 'platform.backup_connection_tested', null, null, ['ok' => $r['ok']]);

        return back()->with($r['ok'] ? 'status' : 'error', $r['ok'] ? 'All checks passed.' : 'Some checks failed — see the results below.');
    }

    public function testNotification(\App\Services\Backups\BackupNotifier $notifier)
    {
        $this->authorizeAdmin();
        $to = $this->settings->notifyRecipients();
        if (! $to) throw new DomainException('No recipients: add notification emails, or make sure admin users have an email address.');
        $notifier->alert('Test backup notification', 'This is a test of backup alerts from McNutt Finance. Failure alerts and the weekly summary will arrive like this.');

        return back()->with('status', 'Test notification sent to '.implode(', ', $to).'.');
    }

    public function saveBusinesses(Request $request)
    {
        $this->authorizeAdmin();
        $ids = array_map('intval', (array) $request->input('enabled', []));
        $before = Business::where('backups_enabled', true)->pluck('id')->all();
        Business::query()->update(['backups_enabled' => false]);
        Business::whereIn('id', $ids)->update(['backups_enabled' => true]);
        Audit::log(null, 'platform.backup_businesses_updated', null, ['enabled' => $before], ['enabled' => $ids]);

        return back()->with('status', 'Backup selection saved for '.count($ids).' business(es).');
    }

    public function run(string $ulid, BackupRunner $runner)
    {
        $this->authorizeAdmin();
        if (! $this->settings->configured()) throw new DomainException('Configure backup storage first.');
        $b = Business::where('ulid', strtolower($ulid))->firstOrFail();
        $snap = $runner->run($b, 'manual');
        Audit::log(null, 'platform.backup_run', $snap, null, ['business' => $b->ulid, 'type' => $snap->type, 'status' => $snap->status]);

        return back()->with($snap->isComplete() ? 'status' : 'error', $snap->isComplete()
            ? ucfirst($snap->type).' backup of '.$b->name().' completed.'
            : 'Backup of '.$b->name().' failed: '.$snap->error);
    }
}
