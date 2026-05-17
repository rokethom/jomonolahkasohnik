<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Services\AiMonitoringService;
use Filament\Pages\Page;

class AiMonitoringPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Monitoring';

    protected static ?string $title = 'AI Monitoring & Security';

    protected static ?string $slug = 'ai-monitoring';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.ai-monitoring-page';

    protected static ?string $pollingInterval = '15s';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    protected function getViewData(): array
    {
        $service = app(AiMonitoringService::class);

        return [
            'ai' => $service->dashboard(),
            'security' => $service->securityDashboard(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }
}
