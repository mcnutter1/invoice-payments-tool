<?php

namespace App\Services\Notifications;

final class OutgoingMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $from,
        public readonly string $subject,
        /** HTML document; the notification service auto-detects HTML in `body`. */
        public readonly string $body,
        public readonly string $correlationId,
    ) {}
}
