<?php

namespace App\Services\Payments;

class StripeError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly ?string $stripeCode = null, public readonly ?string $declineCode = null)
    {
        parent::__construct($message, $status);
    }
}
