<?php

namespace App\Services\Notifications;

final class SendResult
{
    /** @param string $status accepted|transient|permanent|ambiguous */
    public function __construct(
        public readonly string $status,
        public readonly ?int $httpStatus = null,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {}
}
