<?php

namespace App\Providers;

use App\Services\SettingService;
use App\Contracts\Repositories\BranchRepositoryInterface;
use App\Contracts\Repositories\GeojsonRegionRepositoryInterface;
use App\Contracts\Repositories\PricingRingRepositoryInterface;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HomeItem;
use App\Models\HomeSection;
use App\Observers\CmsCacheObserver;
use App\Repositories\BranchRepository;
use App\Repositories\GeojsonRegionRepository;
use App\Repositories\PricingRingRepository;
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
        $this->app->bind(GeojsonRegionRepositoryInterface::class, GeojsonRegionRepository::class);
        $this->app->bind(PricingRingRepositoryInterface::class, PricingRingRepository::class);
        $this->app->bind(BranchRepositoryInterface::class, BranchRepository::class);
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
