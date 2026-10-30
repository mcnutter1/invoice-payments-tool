<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/** Development-only gateway. Refused when APP_ENV=production. Writes each message to storage/logs/mail-dev/*.html. */
class LogNotificationGateway implements NotificationGateway
{
    public static array $sent = [];

    public function isReal(): bool
    {
        return false;
    }

    public function send(OutgoingMessage $m): SendResult
    {
        self::$sent[] = $m;
        if (! app()->runningUnitTests()) {
            $dir = storage_path('logs/mail-dev');
            if (! is_dir($dir)) mkdir($dir, 0775, true);
            $file = $dir.'/'.now()->format('Ymd-His').'-'.substr(md5($m->correlationId), 0, 8).'.html';
            file_put_contents($file, "<!-- To: {$m->to} | From: {$m->from} | Subject: {$m->subject} -->\n".$m->body);
            Log::info('mail-dev', ['to' => $m->to, 'subject' => $m->subject, 'file' => $file]);
        }

        return new SendResult('accepted', 200, 'dev-'.substr(md5($m->correlationId), 0, 12));
    }

    public function health(): array
    {
        return ['status' => 'healthy', 'message' => 'Development log transport — emails are written to storage/logs/mail-dev/, not sent'];
    }
}
