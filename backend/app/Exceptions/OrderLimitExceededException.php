<?php

namespace App\Exceptions;

use RuntimeException;

class OrderLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Anda melebihi batas order aktif. Silakan selesaikan salah satu pesanan terlebih dahulu.',
        public readonly int $activeOrders = 0,
        public readonly int $maxOrders = 3,
    ) {
        parent::__construct($message);
    }
}
