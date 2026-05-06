<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Widgets\Widget;

class DriverLocationMapWidget extends Widget
{
    protected static string $view = 'filament.widgets.driver-location-map';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $drivers = User::query()
            ->with(['latestLocationLog', 'branch'])
            ->where('role', UserRole::Driver->value)
            ->whereHas('latestLocationLog')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (User $driver): array => [
                'name' => $driver->name,
                'username' => $driver->username,
                'branch' => $driver->branch?->name,
                'lat' => (float) $driver->latestLocationLog->latitude,
                'lng' => (float) $driver->latestLocationLog->longitude,
                'is_suspicious' => (bool) $driver->latestLocationLog->is_suspicious,
                'is_valid' => (bool) $driver->latestLocationLog->is_valid,
                'reason' => $driver->latestLocationLog->suspicion_reason,
                'logged_at' => $driver->latestLocationLog->created_at?->diffForHumans(),
            ])
            ->values();

        return [
            'drivers' => $drivers,
        ];
    }
}
