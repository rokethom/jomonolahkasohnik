<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ModernStatsOverview;
use App\Filament\Widgets\RecentActivityWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->darkMode()
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
                </style>
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
