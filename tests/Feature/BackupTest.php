<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BackupChange;
use App\Models\BackupRecord;
use App\Models\BackupSnapshot;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Services\Backups\BackupReader;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupStore;
use App\Services\Backups\S3Store;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\Http;
use Tests\Support\MemoryBackupStore;
use Tests\TestCase;

class BackupTest extends TestCase
{
    private MemoryBackupStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new MemoryBackupStore;
        $this->app->instance(BackupStore::class, $this->store);
    }

    private function setupBusiness(): array
    {
        $owner = $this->makeUser();
        $biz = $this->makeBusiness($owner);
        $client = $this->makeClient($biz, 'Acme Widgets');
        $this->actingSystem($owner);
        $svc = app(InvoiceService::class);
        $inv = $svc->issue($svc->saveDraft($biz, null, ['client_id' => $client->id, 'lines' => [['name' => 'Consulting', 'quantity' => '2', 'unit_price' => '500']]]));

        return [$owner, $biz, $client, $inv];
    }

    public function test_daily_full_then_hourly_deltas_track_changes_and_deletions(): void
    {
        [, $biz, $client] = $this->setupBusiness();
        $runner = app(BackupRunner::class);
        $full = $runner->run($biz);
        $this->assertSame('completed', $full->status, (string) $full->error);
        $this->assertSame('full', $full->type);
        $this->assertStringContainsString('/businesses/'.$biz->ulid.'/snapshots/', $full->object_key);
        $this->assertStringNotContainsString('Acme Widgets', $this->store->objects[$full->object_key]); // encrypted at rest
        $this->assertTrue(BackupRecord::where('business_id', $biz->id)->where('table_name', 'clients')->where('label', 'Acme Widgets')->exists());

        $this->travel(1)->hours();
        $client->update(['display_name' => 'Acme Widgets Inc']);
        $temp = $this->makeClient($biz, 'Temporary Co');
        $delta = $runner->run($biz);
        $this->assertSame('delta', $delta->type);
        $this->assertSame($full->id, $delta->base_snapshot_id);
        $actions = BackupChange::where('snapshot_id', $delta->id)->where('table_name', 'clients')->pluck('action', 'label')->all();
        $this->assertSame(['Acme Widgets Inc' => 'updated', 'Temporary Co' => 'created'], $actions);

        $this->travel(1)->hours();
        $temp->delete();
        $delta2 = $runner->run($biz);
        $this->assertSame('deleted', BackupChange::where('snapshot_id', $delta2->id)->where('record_key', (string) $temp->id)->value('action'));
        $this->assertSame(0, BackupChange::where('snapshot_id', $delta2->id)->where('record_key', (string) $client->id)->count());

        // Rebuilding any point gives the business exactly as it was then.
        $reader = app(BackupReader::class);
        $this->assertSame('Acme Widgets', $reader->state($full)['rows']['clients'][(string) $client->id]['display_name']);
        $this->assertArrayHasKey((string) $temp->id, $reader->state($delta)['rows']['clients']);
        $this->assertArrayNotHasKey((string) $temp->id, $reader->state($delta2)['rows']['clients']);
        $this->assertSame('Acme Widgets Inc', $reader->state($delta2)['rows']['clients'][(string) $client->id]['display_name']);

        // Next day starts a new full.
        $this->travel(1)->days();
        $this->assertSame('full', $runner->run($biz)->type);
    }

    public function test_record_restore_overwrite_missing_and_copy_with_children(): void
    {
        [$owner, $biz, $client, $inv] = $this->setupBusiness();
        $snap = app(BackupRunner::class)->run($biz);
        $this->travel(1)->hours();
        $client->update(['display_name' => 'Renamed']);
        $gone = $this->makeClient($biz, 'Deleted Later');
        $snap2 = app(BackupRunner::class)->run($biz);
        $gone->delete();
        $lines = \DB::table('invoice_lines')->where('invoice_id', $inv->id)->count();

        $page = $this->signIn($owner);
        $page->get("/b/{$biz->ulid}/backups?q=Renamed")->assertOk()->assertSee('Renamed');
        $page->get("/b/{$biz->ulid}/backups/records/clients/{$client->id}?v={$snap->ulid}")->assertOk()
            ->assertSee('Acme Widgets')->assertSee('Overwrite the current version')->assertSee('Restore as a new copy');

        $page->post("/b/{$biz->ulid}/backups/{$snap->ulid}/records/clients/{$client->id}/restore", ['mode' => 'overwrite'])->assertSessionHas('status');
        $this->assertSame('Acme Widgets', $client->fresh()->display_name);

        $page->post("/b/{$biz->ulid}/backups/{$snap2->ulid}/records/clients/{$client->id}/restore", ['mode' => 'missing'])->assertSessionHas('error');
        $page->post("/b/{$biz->ulid}/backups/{$snap2->ulid}/records/clients/{$gone->id}/restore", ['mode' => 'missing'])->assertSessionHas('status');
        $this->assertSame('Deleted Later', Client::find($gone->id)?->display_name);

        $page->post("/b/{$biz->ulid}/backups/{$snap->ulid}/records/invoices/{$inv->id}/restore", ['mode' => 'copy'])->assertSessionHas('status');
        $copy = Invoice::where('business_id', $biz->id)->where('id', '!=', $inv->id)->latest('id')->first();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->number);
        $this->assertSame($lines, \DB::table('invoice_lines')->where('invoice_id', $copy->id)->count());
        $this->assertSame($lines, \DB::table('invoice_lines')->where('invoice_id', $inv->id)->count());
        $this->assertTrue(AuditEvent::where('action', 'backup.record_restored')->exists());

        // Another business's backups are never reachable.
        $other = $this->makeBusiness($this->makeUser('u_other'));
        $this->signIn($owner)->get("/b/{$other->ulid}/backups")->assertNotFound();
    }

    public function test_whole_business_rollback_takes_safety_backup_and_keeps_audit_log(): void
    {
        [$owner, $biz, $client] = $this->setupBusiness();
        $snap = app(BackupRunner::class)->run($biz);
        $this->travel(1)->hours();
        $later = $this->makeClient($biz, 'Added After Backup');
        $client->update(['display_name' => 'Changed After Backup']);
        $auditBefore = AuditEvent::where('business_id', $biz->id)->count();

        $this->signIn($owner)->post("/b/{$biz->ulid}/backups/{$snap->ulid}/rollback", ['confirm' => 'wrong'])->assertSessionHas('error');
        $this->signIn($owner)->post("/b/{$biz->ulid}/backups/{$snap->ulid}/rollback", ['confirm' => $biz->name()])->assertRedirect();

        $this->assertNull(Client::find($later->id));
        $this->assertSame('Acme Widgets', $client->fresh()->display_name);
        $this->assertTrue(BackupSnapshot::where('business_id', $biz->id)->where('trigger', 'pre_restore')->where('status', 'completed')->exists());
        $this->assertGreaterThanOrEqual($auditBefore, AuditEvent::where('business_id', $biz->id)->count());
        $this->assertTrue(AuditEvent::where('action', 'backup.business_rolled_back')->exists());
        $this->signIn($owner)->get("/b/{$biz->ulid}/invoices")->assertOk();
    }

    public function test_backed_up_files_are_previewable_and_recovered(): void
    {
        [$owner, $biz, , $inv] = $this->setupBusiness();
        $snap = app(BackupRunner::class)->run($biz);
        $doc = $inv->latestDocument()->document;
        $this->assertGreaterThan(0, $snap->files_uploaded);
        $this->signIn($owner)->get("/b/{$biz->ulid}/backups/files/{$doc->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->signIn($owner)->get("/b/{$biz->ulid}/backups/records/invoices/{$inv->id}")->assertOk()->assertSee('iframe', false);

        unlink($doc->absolutePath());
        $this->signIn($owner)->post("/b/{$biz->ulid}/backups/{$snap->ulid}/records/invoice_document_versions/{$inv->latestDocument()->id}/restore", ['mode' => 'overwrite'])->assertSessionHas('status');
        $this->assertFileExists($doc->absolutePath());
    }

    public function test_platform_admin_configures_s3_and_selects_businesses(): void
    {
        $admin = $this->makeUser('u_root', null, ['admin', 'user']);
        $plain = $this->makeUser('u_plain');
        $this->signIn($plain)->get('/platform/backups')->assertForbidden();
        $this->signIn($admin)->get('/platform/backups')->assertOk()->assertSee('S3 storage');

        $this->signIn($admin)->post('/platform/backups/settings', [
            'bucket' => 'acme-backups', 'region' => 'us-east-2', 'access_key_id' => 'AKIAIOSFODNN7EXAMPLE', 'secret_access_key' => 'shh-secret-wJalrXUtnFEMI/K7MDENG',
            'prefix' => 'prod', 'delta_retention_days' => 7, 'full_retention_days' => 35, 'default_enabled' => '0',
            'class_delta' => 'STANDARD', 'class_full' => 'GLACIER_IR', 'class_files' => 'GLACIER_IR', 'notify_emails' => 'ops@example.test',
        ])->assertSessionHas('status');
        $this->assertSame('acme-backups', PlatformSetting::read('backups')['bucket']);
        $this->assertStringNotContainsString('shh-secret', (string) PlatformSetting::find('backups.secret')->value);
        $this->assertSame('shh-secret-wJalrXUtnFEMI/K7MDENG', PlatformSetting::readSecret('backups.secret'));
        $this->signIn($admin)->post('/platform/backups/settings', [
            'bucket' => 'Bad_Bucket', 'region' => 'mars-1', 'access_key_id' => 'short', 'delta_retention_days' => 7, 'full_retention_days' => 3,
            'class_delta' => 'DEEP_ARCHIVE', 'class_full' => 'GLACIER_IR', 'class_files' => 'GLACIER_IR', 'notify_emails' => 'not-an-email',
        ])->assertSessionHasErrors(['bucket', 'region', 'access_key_id', 'full_retention_days', 'class_delta', 'notify_emails']);
        $this->signIn($admin)->get('/platform/backups')->assertOk()->assertSee('arn:aws:s3:::acme-backups/prod/*', false)->assertSee('DenyInsecureTransport');

        $b1 = $this->makeBusiness($this->makeUser('u_a'), 'One');
        $this->assertFalse($b1->fresh()->backups_enabled); // default turned off above
        $b2 = $this->makeBusiness($this->makeUser('u_b'), 'Two');
        $this->signIn($admin)->post('/platform/backups/businesses', ['enabled' => [$b2->id]])->assertSessionHas('status');
        $this->assertFalse($b1->fresh()->backups_enabled);
        $this->assertTrue($b2->fresh()->backups_enabled);

        $this->artisan('finance:backups')->assertSuccessful();
        $this->assertSame(0, BackupSnapshot::where('business_id', $b1->id)->count());
        $this->assertSame(1, BackupSnapshot::where('business_id', $b2->id)->count());
    }

    public function test_storage_classes_and_deltas_never_read_from_s3(): void
    {
        [, $biz] = $this->setupBusiness();
        $runner = app(BackupRunner::class);
        $full = $runner->run($biz);
        $this->assertSame('GLACIER_IR', $this->store->classes[$full->object_key]);
        $fileKey = \App\Models\BackupFile::where('business_id', $biz->id)->value('object_key');
        $this->assertSame('GLACIER_IR', $this->store->classes[$fileKey]);
        $this->travel(1)->hours();
        $this->makeClient($biz, 'New One');
        $reads = $this->store->reads;
        $delta = $runner->run($biz);
        $this->assertSame('STANDARD', $this->store->classes[$delta->object_key]);
        $this->assertSame($reads, $this->store->reads);
        $this->assertNull($full->fresh()->manifest);
        $this->assertNotNull($delta->fresh()->manifest);
    }

    public function test_connection_diagnostics_explain_aws_errors(): void
    {
        $admin = $this->makeUser('u_root', null, ['admin']);
        $this->signIn($admin)->post('/platform/backups/test')->assertSessionHas('status');
        $this->assertTrue(PlatformSetting::read('backups.last_test')['ok']);

        $this->app->forgetInstance(BackupStore::class);
        app(\App\Services\Backups\BackupSettings::class)->save(['bucket' => 'acme-backups', 'region' => 'us-east-1', 'access_key_id' => 'AKIAIOSFODNN7EXAMPLE'], 'secret-secret-secret-secret');
        Http::fake(['*' => Http::response('<?xml version="1.0"?><Error><Code>AuthorizationHeaderMalformed</Code><Message>wrong region</Message><Region>eu-west-1</Region></Error>', 400)]);
        $this->signIn($admin)->post('/platform/backups/test')->assertSessionHas('error');
        $steps = PlatformSetting::read('backups.last_test')['steps'];
        $this->assertFalse(end($steps)['ok']);
        $this->assertStringContainsString('different region (eu-west-1)', end($steps)['detail']);
        $this->signIn($admin)->get('/platform/backups')->assertSee('different region (eu-west-1)');
    }

    public function test_failure_alerts_are_throttled_and_recovery_is_announced(): void
    {
        \App\Services\Notifications\LogNotificationGateway::$sent = [];
        $this->makeUser('u_root', 'root@example.test', ['admin']);
        [, $biz] = $this->setupBusiness();
        $broken = new class implements BackupStore {
            public function put(string $key, string $bytes, ?string $storageClass = null): void { throw new \RuntimeException('Access denied'); }
            public function get(string $key): string { throw new \RuntimeException('nope'); }
            public function delete(string $key): void {}
        };
        $this->app->instance(BackupStore::class, $broken);
        $this->artisan('finance:backups')->assertFailed();
        $subjects = fn () => array_map(fn ($m) => $m->to.': '.$m->subject, \App\Services\Notifications\LogNotificationGateway::$sent);
        $this->assertSame(['root@example.test: Backup failed for 1 business'], $subjects());
        $this->travel(1)->hours();
        $this->artisan('finance:backups')->assertFailed();
        $this->assertCount(1, $subjects()); // no repeat within 24h

        $this->app->instance(BackupStore::class, $this->store);
        $this->travel(1)->hours();
        $this->artisan('finance:backups')->assertSuccessful();
        $this->assertSame('root@example.test: Backups recovered for 1 business', $subjects()[1]);
    }

    public function test_weekly_summary_restore_tests_latest_backup(): void
    {
        \App\Services\Notifications\LogNotificationGateway::$sent = [];
        app(\App\Services\Backups\BackupSettings::class)->save(['notify_emails' => 'ops@example.test'], null);
        [, $biz] = $this->setupBusiness();
        $snap = app(BackupRunner::class)->run($biz);
        $this->artisan('finance:backups-weekly')->assertSuccessful();
        $this->assertNotNull($snap->fresh()->verified_at);
        $this->assertNull($snap->fresh()->verify_error);
        $m = end(\App\Services\Notifications\LogNotificationGateway::$sent);
        $this->assertSame('ops@example.test', $m->to);
        $this->assertSame('Weekly backup summary', $m->subject);
        $this->assertStringContainsString('restore test passed', $m->body);

        // A damaged object is caught by the restore test.
        $this->store->objects[$snap->object_key] .= 'x';
        $this->artisan('finance:backups-weekly')->assertSuccessful();
        $this->assertStringContainsString('checksum mismatch', (string) $snap->fresh()->verify_error);
        $this->assertSame('Weekly backup summary — restore test failed', end(\App\Services\Notifications\LogNotificationGateway::$sent)->subject);
    }

    public function test_s3_requests_are_signed_with_sigv4(): void
    {
        Http::fake(['*' => Http::response('hello', 200)]);
        $s3 = new S3Store(['bucket' => 'acme-backups', 'region' => 'us-east-2', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret', 'endpoint' => null, 'path_style' => false]);
        $s3->put('prod/businesses/abc/x.bin', 'payload', 'GLACIER_IR');
        $this->assertSame('hello', $s3->get('prod/businesses/abc/x.bin'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r->url() === 'https://acme-backups.s3.us-east-2.amazonaws.com/prod/businesses/abc/x.bin'
            && $r->header('Content-Type') === ['application/octet-stream']
            && str_starts_with($r->header('authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/')
            && str_contains($r->header('authorization')[0] ?? '', '/us-east-2/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-server-side-encryption;x-amz-storage-class,')
            && ($r->header('x-amz-storage-class')[0] ?? '') === 'GLACIER_IR'
            && ($r->header('x-amz-content-sha256')[0] ?? '') === hash('sha256', 'payload')
            && ($r->header('x-amz-server-side-encryption')[0] ?? '') === 'AES256');
    }
}
