<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\User;
use Filament\Widgets\Widget;

class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-activity';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'users' => User::query()
                ->with('branch')
                ->latest()
                ->limit(6)
                ->get(),
            'orders' => Order::query()
                ->with(['user', 'driver.user'])
                ->latest()
                ->limit(6)
                ->get(),
        ];
    }
}
