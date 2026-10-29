<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/** Development-only gateway. Refused when APP_ENV=production. Writes full messages to storage/logs/mail-dev.log. */
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
            Log::build(['driver' => 'single', 'path' => storage_path('logs/mail-dev.log')])
                ->info("To: {$m->to}\nFrom: {$m->from}\nSubject: {$m->subject}\n\n{$m->textBody}\n");
        }

        return new SendResult('accepted', 200, 'dev-'.substr(md5($m->correlationId), 0, 12));
    }

    public function health(): array
    {
        return ['status' => 'healthy', 'message' => 'Development log transport — emails are written to storage/logs/mail-dev.log, not sent'];
    }
}
