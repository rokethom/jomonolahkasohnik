<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Services\AiMonitoringService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiMonitoringOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    protected function getStats(): array
    {
        $service = app(AiMonitoringService::class);
        $ai = $service->dashboard();
        $security = $service->securityDashboard();

        return [
            Stat::make('AI Status', $ai['active_status']['enabled'] ? 'ACTIVE' : 'OFF')
                ->description($ai['active_status']['provider'].' · '.$ai['active_status']['mode'])
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($ai['active_status']['enabled'] ? 'success' : 'gray'),
            Stat::make('AI Fallback', number_format((int) $ai['analytics']['fallback_count']))
                ->description('Recent fallback attempts')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color(((int) $ai['analytics']['fallback_count']) > 3 ? 'warning' : 'success'),
            Stat::make('AI Latency', $ai['analytics']['avg_latency_seconds'].'s')
                ->description('Average response time')
                ->descriptionIcon('heroicon-m-clock')
                ->color(((float) $ai['analytics']['avg_latency_seconds']) >= 10 ? 'warning' : 'info'),
            Stat::make('AI Security', strtoupper($security['summary']['status']))
                ->description($security['summary']['login_anomalies'].' login anomaly · '.$security['summary']['temporary_blacklist'].' blacklist')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($security['summary']['status'] === 'normal' ? 'success' : 'warning'),
        ];
    }
}
