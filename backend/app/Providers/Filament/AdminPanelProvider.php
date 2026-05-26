<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\Analytics\AreaAnalyticsTableWidget;
use App\Filament\Widgets\Analytics\AreaStatsWidget;
use App\Filament\Widgets\Analytics\DriverRankingTableWidget;
use App\Filament\Widgets\Analytics\DriverStatsWidget;
use App\Filament\Widgets\Analytics\OverviewStatsWidget;
use App\Filament\Widgets\Analytics\PeakHourChartWidget;
use App\Filament\Widgets\Analytics\PeakHourHeatmapWidget;
use App\Filament\Widgets\Analytics\PeakHourStatsWidget;
use App\Filament\Widgets\Analytics\RevenueComparisonChartWidget;
use App\Filament\Widgets\Analytics\RevenueStatsWidget;
use App\Filament\Widgets\Analytics\RevenueTrendChartWidget;
use App\Filament\Widgets\Analytics\ServiceAnalyticsTableWidget;
use App\Filament\Widgets\Analytics\ServiceGrowthChartWidget;
use App\Filament\Widgets\Analytics\ServiceOrdersChartWidget;
use App\Filament\Widgets\Analytics\ServicePopularityChartWidget;
use App\Filament\Widgets\Analytics\ServiceRevenueChartWidget;
use App\Filament\Widgets\Analytics\TopAreasChartWidget;
use App\Filament\Widgets\ModernStatsOverview;
use App\Filament\Widgets\RecentActivityWidget;
use App\Services\SettingService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(fn (): string => app(SettingService::class)->brandingBackendName())
            ->login()
            ->darkMode()
            ->breadcrumbs()
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Reports')->collapsed(),
                NavigationGroup::make('Driver')->collapsed(),
                NavigationGroup::make('Management')->collapsed(),
                NavigationGroup::make('Pricing Management')->collapsed(),
                NavigationGroup::make('Pricing')->collapsed(),
                NavigationGroup::make('AI')->collapsed(),
                NavigationGroup::make('Location')->collapsed(),
                NavigationGroup::make('Master Data GeoJSON')->collapsed(),
                NavigationGroup::make('Home CMS')->collapsed(),
                NavigationGroup::make('Analytics')->collapsed(),
                NavigationGroup::make('Dokumentasi')->collapsed(),
                NavigationGroup::make('System')->collapsed(),
            ])
            ->colors([
                'primary' => Color::Amber,
                'orange' => Color::Orange,
                'purple' => Color::Purple,
                'cyan' => Color::Cyan,
            ])
            ->renderHook('panels::head.end', fn (): HtmlString => new HtmlString(<<<'HTML'
                <style>
                    .fi-body,
                    .fi-main,
                    .fi-sidebar,
                    .fi-topbar nav {
                        background: #090b10 !important;
                    }

                    .fi-main {
                        color: #f8fafc;
                    }

                    .fi-section,
                    .fi-ta-ctn,
                    .fi-fo-field-wrp,
                    .fi-wi-stats-overview-stat {
                        background-color: #17181c !important;
                        border-color: rgba(148, 163, 184, 0.14) !important;
                    }

                    .fi-section-content,
                    .fi-ta-content,
                    .fi-ta-table,
                    .fi-ta-row,
                    .fi-ta-cell,
                    .fi-ta-header,
                    .fi-ta-summary-row,
                    .fi-wi-widget > div {
                        background-color: transparent !important;
                    }

                    .fi-section-content-ctn,
                    .fi-ta-header-toolbar,
                    .fi-pagination,
                    .fi-modal-window {
                        background-color: #17181c !important;
                        border-color: rgba(148, 163, 184, 0.14) !important;
                    }

                    .fi-modal-window,
                    .fi-modal-window > div,
                    .fi-modal-content,
                    .fi-modal-header,
                    .fi-modal-footer {
                        background-color: #17181c !important;
                        color: #f8fafc !important;
                    }

                    .fi-modal-heading,
                    .fi-modal-description,
                    .fi-modal-window label,
                    .fi-modal-window .fi-fo-field-wrp-label span,
                    .fi-modal-window .fi-fo-field-wrp-helper-text {
                        color: #e5e7eb !important;
                    }

                    .fi-input-wrp,
                    .fi-select-input,
                    .fi-textarea {
                        background-color: #0f172a !important;
                        border-color: rgba(148, 163, 184, 0.18) !important;
                        color: #f8fafc !important;
                    }

                    .fi-ta-table thead,
                    .fi-ta-table tbody,
                    .fi-ta-table tr {
                        background: transparent !important;
                    }

                    .fi-ta-row:hover {
                        background-color: rgba(30, 41, 59, 0.72) !important;
                    }

                    .fi-breadcrumbs,
                    .fi-breadcrumbs a,
                    .fi-breadcrumbs span {
                        color: #cbd5e1 !important;
                    }

                    .fi-sidebar-item-active > .fi-sidebar-item-button,
                    .fi-sidebar-item-button[aria-current='page'] {
                        background: linear-gradient(135deg, rgba(245, 158, 11, 0.22), rgba(14, 165, 233, 0.14)) !important;
                        border: 1px solid rgba(251, 191, 36, 0.28) !important;
                    }

                    .fi-sidebar-item-active .fi-sidebar-item-label,
                    .fi-sidebar-item-active svg {
                        color: #fbbf24 !important;
                    }

                    .jojo-recent-pages {
                        align-items: center;
                        display: flex;
                        gap: .45rem;
                        margin-bottom: .75rem;
                        overflow-x: auto;
                        padding-bottom: .25rem;
                    }

                    .jojo-recent-pages a {
                        background: rgba(15, 23, 42, .8);
                        border: 1px solid rgba(148, 163, 184, .18);
                        border-radius: 999px;
                        color: #cbd5e1;
                        font-size: .76rem;
                        font-weight: 700;
                        padding: .38rem .68rem;
                        white-space: nowrap;
                    }
                </style>
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const sidebar = document.querySelector('.fi-sidebar-nav');
                        const key = 'jojo:filament-sidebar-scroll';

                        if (sidebar) {
                            const savedTop = sessionStorage.getItem(key);
                            if (savedTop) sidebar.scrollTop = Number(savedTop);
                            sidebar.addEventListener('scroll', () => sessionStorage.setItem(key, String(sidebar.scrollTop)), { passive: true });
                            const active = sidebar.querySelector('.fi-sidebar-item-active, [aria-current="page"]');
                            if (active && !savedTop) active.scrollIntoView({ block: 'center' });
                        }

                        const title = document.querySelector('h1')?.textContent?.trim() || document.title.replace(' - Jojoapp', '').trim();
                        const url = window.location.href;
                        const historyKey = 'jojo:recent-pages';
                        const pages = JSON.parse(localStorage.getItem(historyKey) || '[]').filter((item) => item.url !== url);
                        pages.unshift({ title, url });
                        localStorage.setItem(historyKey, JSON.stringify(pages.slice(0, 6)));

                        const main = document.querySelector('.fi-main');
                        if (main && !document.querySelector('.jojo-recent-pages')) {
                            const wrap = document.createElement('div');
                            wrap.className = 'jojo-recent-pages';
                            JSON.parse(localStorage.getItem(historyKey) || '[]').slice(0, 5).forEach((item) => {
                                const link = document.createElement('a');
                                link.href = item.url;
                                link.textContent = item.title || 'Recent Page';
                                wrap.appendChild(link);
                            });
                            main.prepend(wrap);
                        }
                    });
                </script>
            HTML))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                ModernStatsOverview::class,
                RecentActivityWidget::class,
                Widgets\AccountWidget::class,
            ])
            ->livewireComponents([
                AreaAnalyticsTableWidget::class,
                AreaStatsWidget::class,
                DriverRankingTableWidget::class,
                DriverStatsWidget::class,
                OverviewStatsWidget::class,
                PeakHourChartWidget::class,
                PeakHourHeatmapWidget::class,
                PeakHourStatsWidget::class,
                RevenueComparisonChartWidget::class,
                RevenueStatsWidget::class,
                RevenueTrendChartWidget::class,
                ServiceAnalyticsTableWidget::class,
                ServiceGrowthChartWidget::class,
                ServiceOrdersChartWidget::class,
                ServicePopularityChartWidget::class,
                ServiceRevenueChartWidget::class,
                TopAreasChartWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
