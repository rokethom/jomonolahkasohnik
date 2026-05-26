<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalyticsCacheService
{
    public function remember(string $key, int $seconds, Closure $callback): mixed
    {
        $version = Cache::get('analytics:cache-version', 'v1');
        $cacheKey = "analytics:{$version}:{$key}";

        try {
            return Cache::store('redis')->tags(['analytics'])->remember($cacheKey, $seconds, $callback);
        } catch (Throwable) {
            return Cache::remember($cacheKey, $seconds, $callback);
        }
    }

    public function flush(): void
    {
        try {
            Cache::store('redis')->tags(['analytics'])->flush();
        } catch (Throwable) {
            Cache::forever('analytics:cache-version', 'v'.now()->timestamp);
        }
    }
}
