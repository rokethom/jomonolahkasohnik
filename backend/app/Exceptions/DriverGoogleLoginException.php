<?php

namespace App\Exceptions;

use RuntimeException;

class DriverGoogleLoginException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 403,
        private readonly string $reason = 'driver_google_login_failed',
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
