<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'CREATED';
    case SearchingDriver = 'SEARCHING_DRIVER';
    case DriverAccepted = 'DRIVER_ACCEPTED';
    case DriverOnTheWay = 'DRIVER_ON_THE_WAY';
    case ArrivedPickup = 'ARRIVED_PICKUP';
    case OnGoing = 'ON_GOING';
    case PendingCancel = 'PENDING_CANCEL';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
