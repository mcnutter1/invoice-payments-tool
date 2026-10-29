<?php

namespace App\Console\Commands;

use App\Services\Auth\AuthGateway;
use App\Services\Notifications\NotificationGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Environment validation. Exit code 1 on blocking problems; warnings are listed separately. */
class Preflight extends Command
{
    protected $signature = 'finance:preflight {--no-network : Skip integration reachability checks}';

    protected $description = 'Validate configuration, database, storage and integrations.';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];
        if (version_compare(PHP_VERSION, '8.2.0', '<')) $errors[] = 'PHP 8.2+ required (found '.PHP_VERSION.')';
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'dom', 'gd', 'fileinfo', 'bcmath', 'intl', 'zip', 'xml', 'curl'] as $ext) {
            if (! extension_loaded($ext)) ($ext === 'bcmath' || $ext === 'intl' || $ext === 'zip' ? $warnings[] = "PHP extension {$ext} not loaded" : $errors[] = "PHP extension {$ext} missing");
        }
        if (! config('app.key')) $errors[] = 'APP_KEY is not set';
        if (app()->environment('production')) {
            if (config('app.debug')) $errors[] = 'APP_DEBUG must be false in production';
            if (! str_starts_with((string) config('app.url'), 'https://')) $errors[] = 'APP_URL must be https in production';
            if (config('finance.auth.driver') !== 'mcnutt') $errors[] = 'AUTH_DRIVER must be mcnutt in production';
            if (config('finance.notifications.driver') !== 'mcnutt') $errors[] = 'NOTIFICATIONS_DRIVER must be mcnutt in production';
            if (! config('session.secure')) $warnings[] = 'SESSION_SECURE_COOKIE should be true';
        }
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            $errors[] = 'Database connection failed: '.class_basename($e);
        }
        $root = config('finance.storage.private_root');
        if (! is_dir($root) || ! is_writable($root)) $errors[] = "Private storage not writable: {$root}";
        if (str_starts_with(realpath($root) ?: $root, public_path())) $errors[] = 'Private storage must be outside public/';

        foreach (['AUTH_APP_ID' => config('finance.auth.app_id'), 'AUTH_APP_SECRET' => config('finance.auth.app_secret')] as $k => $v) {
            if (! $v) $warnings[] = "{$k} not set — sign-in is unconfigured";
        }
        foreach (['NOTIFICATIONS_API_TOKEN' => config('finance.notifications.api_token'), 'NOTIFICATIONS_FROM_EMAIL' => config('finance.notifications.default_from_email')] as $k => $v) {
            if (! $v) $warnings[] = "{$k} not set — email delivery is unconfigured";
        }
        if (! $this->option('no-network')) {
            $auth = app(AuthGateway::class)->health();
            $mail = app(NotificationGateway::class)->health();
            $this->line("Auth integration: {$auth['status']} — {$auth['message']}");
            $this->line("Notification integration: {$mail['status']} — {$mail['message']}");
            if ($auth['status'] === 'failed') $warnings[] = 'Auth service check failed';
            if ($mail['status'] === 'failed') $warnings[] = 'Notification service check failed';
        }
        foreach ($warnings as $w) $this->warn('WARN  '.$w);
        foreach ($errors as $e) $this->error('ERROR '.$e);
        if (! $errors) $this->info('Preflight passed'.($warnings ? ' with warnings.' : '.'));

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
