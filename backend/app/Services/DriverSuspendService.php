<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverSuspension;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DriverSuspendService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function suspend(
        Driver $driver,
        int $hours,
        string $reason,
        ?User $actor = null,
        string $status = 'suspended',
        string $type = 'violation',
    ): DriverSuspension {
        return DB::transaction(function () use ($driver, $hours, $reason, $actor, $status, $type): DriverSuspension {
            $startAt = now();
            $endAt = $startAt->copy()->addHours($hours);

            $suspension = DriverSuspension::query()->create([
                'driver_id' => $driver->id,
                'created_by' => $actor?->id,
                'type' => $type,
                'reason' => $reason,
                'duration' => $hours,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $status,
            ]);

            $driver->update([
                'status' => $status,
                'is_available' => false,
                'suspended_until' => $endAt,
            ]);

            $driver->user?->update([
                'is_suspended' => true,
                'suspension_reason' => $reason,
                'suspended_until' => $endAt,
            ]);

            $this->notifications->sendToUser(
                $driver->user,
                'Akun driver disuspend',
                "Akun Anda disuspend selama {$hours} jam",
                ['type' => 'driver_suspended', 'until' => $endAt->toIso8601String()],
            );

            return $suspension->fresh(['driver.user', 'creator']);
        });
    }

    public function suspendForOperHandle(Driver $driver, ?User $actor = null): DriverSuspension
    {
        $count = (int) $driver->oper_handle_count + 1;
        $hours = $count >= 3 ? 12 : 1;

        $driver->forceFill(['oper_handle_count' => $count])->save();

        return $this->suspend($driver->fresh('user'), $hours, 'Oper handle order oleh driver', $actor, 'suspended', 'oper_handle');
    }

    public function release(Driver $driver, ?User $actor = null): void
    {
        DB::transaction(function () use ($driver): void {
            DriverSuspension::query()
                ->where('driver_id', $driver->id)
                ->whereIn('status', ['active', 'suspended', 'suspended_unpaid'])
                ->update(['status' => 'released', 'end_at' => Carbon::now()]);

            $driver->update([
                'status' => 'active',
                'suspended_until' => null,
            ]);

            $driver->user?->update([
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_until' => null,
            ]);
        });
    }

    public function releaseIfExpired(Driver $driver): bool
    {
        if (
            $driver->status !== 'suspended'
            || ! $driver->suspended_until
            || $driver->suspended_until->isFuture()
        ) {
            return false;
        }

        $this->release($driver->loadMissing('user'));

        return true;
    }

    public function releaseExpiredSuspensions(): int
    {
        $released = 0;

        Driver::query()
            ->with('user')
            ->where('status', 'suspended')
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->chunkById(50, function ($drivers) use (&$released): void {
                foreach ($drivers as $driver) {
                    if ($this->releaseIfExpired($driver)) {
                        $released++;
                    }
                }
            });

        return $released;
    }
}
