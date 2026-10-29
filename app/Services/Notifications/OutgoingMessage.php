<?php

namespace App\Services\Notifications;

final class OutgoingMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $from,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly string $correlationId,
        public readonly ?string $htmlBody = null,
    ) {}
}
