<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverSuspension;
use App\Models\User;

class SuspendService
{
    public function __construct(private readonly DriverSuspendService $base)
    {
    }

    public function suspendViolation(Driver $driver, string $reason, ?User $actor = null, int $hours = 24): DriverSuspension
    {
        return $this->base->suspend($driver->load('user'), $hours, $reason, $actor, 'suspended', 'violation');
    }

    public function suspendUnpaid(Driver $driver, string $reason, ?User $actor = null): DriverSuspension
    {
        return $this->base->suspend($driver->load('user'), 24 * 40, $reason, $actor, 'suspended_unpaid', 'deposit');
    }

    public function suspendPermanent(Driver $driver, string $reason, ?User $actor = null): DriverSuspension
    {
        $driver->forceFill(['permanent_delete_eligible_at' => now()->addMonths(3)])->save();

        return $this->base->suspend($driver->load('user'), 24 * 365, $reason, $actor, 'permanent', 'permanent');
    }

    public function canAcceptOrder(Driver $driver): bool
    {
        return ! in_array($driver->status, ['suspended_unpaid', 'permanent'], true);
    }

    public function canRequestOrder(Driver $driver): bool
    {
        return ! in_array($driver->status, ['suspended_unpaid', 'permanent'], true);
    }

    public function deletePermanentExpiredDrivers(): int
    {
        $count = 0;

        Driver::query()
            ->with('user')
            ->where('status', 'permanent')
            ->where('permanent_delete_eligible_at', '<=', now())
            ->chunkById(50, function ($drivers) use (&$count): void {
                foreach ($drivers as $driver) {
                    $driver->user?->delete();
                    $count++;
                }
            });

        return $count;
    }
}
