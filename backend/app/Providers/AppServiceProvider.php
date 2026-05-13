<?php

namespace App\Providers;

use App\Services\SettingService;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HomeItem;
use App\Models\HomeSection;
use App\Observers\CmsCacheObserver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (Schema::hasTable('app_settings')) {
                app(SettingService::class)->applyToConfig();
            }
        } catch (Throwable) {
            // Database may not be ready while installing, migrating, or caching config.
        }

        Banner::observe(CmsCacheObserver::class);
        HomeSection::observe(CmsCacheObserver::class);
        HomeItem::observe(CmsCacheObserver::class);
        Announcement::observe(CmsCacheObserver::class);
    }
}
