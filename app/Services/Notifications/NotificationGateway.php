<?php

namespace App\Services\Notifications;

interface NotificationGateway
{
    public function send(OutgoingMessage $message): SendResult;

    /** @return array{status:string,message:string} disabled|unconfigured|healthy|failed */
    public function health(): array;

    public function isReal(): bool;
}
