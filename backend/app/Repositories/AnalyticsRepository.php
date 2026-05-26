<?php

namespace App\Repositories;

use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Models\DailyAreaAnalytic;
use App\Models\DailyDriverAnalytic;
use App\Models\DailyServiceAnalytic;
use App\Models\Driver;
use App\Services\AnalyticsCacheService;
use App\Support\AnalyticsPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AnalyticsRepository
{
    public function __construct(private readonly AnalyticsCacheService $cache) {}

    public function overview(AnalyticsPeriod $period): array
    {
        return $this->cache->remember('overview:'.$period->cacheKey(), 60, function () use ($period): array {
            $current = $this->totalFor($period);
            $previous = $this->totalFor($period->previous());
            $driverStats = $this->driverAvailability();

            return [
                ...$current,
                ...$driverStats,
                'orders_trend' => $this->trend($current['orders_count'], $previous['orders_count']),
                'revenue_trend' => $this->trend($current['gross_revenue'], $previous['gross_revenue']),
                'completed_trend' => $this->trend($current['completed_orders'], $previous['completed_orders']),
                'cancelled_trend' => $this->trend($current['cancelled_orders'], $previous['cancelled_orders']),
            ];
        });
    }

    public function serviceTotals(AnalyticsPeriod $period): Collection
    {
        return $this->cache->remember('services:'.$period->cacheKey(), 120, fn (): Collection => $this->serviceQuery($period)->get());
    }

    public function serviceDailyTrend(AnalyticsPeriod $period): array
    {
        return $this->cache->remember('service-trend:'.$period->cacheKey(), 120, function () use ($period): array {
            $rows = DailyServiceAnalytic::query()
                ->where('is_total', false)
                ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->orderBy('analytics_date')
                ->get(['analytics_date', 'service_code', 'orders_count']);
            $topCodes = $rows->groupBy('service_code')
                ->map(fn (Collection $values): int => $values->sum('orders_count'))
                ->sortDesc()
                ->keys()
                ->take(5);
            $labels = collect();
            for ($date = $period->from->copy(); $date->lte($period->to); $date->addDay()) {
                $labels->push($date->format('d M'));
            }

            return [
                'labels' => $labels->all(),
                'series' => $topCodes->mapWithKeys(fn (string $code): array => [
                    $code => $labels->map(function (string $label) use ($rows, $code): int {
                        return (int) $rows
                            ->first(fn (DailyServiceAnalytic $row): bool => $row->service_code === $code && $row->analytics_date->format('d M') === $label)
                            ?->orders_count;
                    })->all(),
                ])->all(),
            ];
        });
    }

    public function serviceQuery(AnalyticsPeriod $period): Builder
    {
        return DailyServiceAnalytic::query()
            ->selectRaw('MIN(id) as id, service_code, MAX(service_name) as service_name')
            ->selectRaw('SUM(orders_count) as orders_count, SUM(completed_orders) as completed_orders, SUM(cancelled_orders) as cancelled_orders')
            ->selectRaw('SUM(gross_revenue) as gross_revenue, SUM(platform_fee) as platform_fee, SUM(rating_sum) as rating_sum, SUM(ratings_count) as ratings_count')
            ->where('is_total', false)
            ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('service_code')
            ->orderByRaw('SUM(orders_count) DESC');
    }

    public function dailyRevenue(AnalyticsPeriod $period): Collection
    {
        return $this->cache->remember('daily-revenue:'.$period->cacheKey(), 120, fn (): Collection => DailyServiceAnalytic::query()
            ->where('is_total', true)
            ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->orderBy('analytics_date')
            ->get(['analytics_date', 'gross_revenue', 'net_revenue', 'driver_payout', 'platform_fee']));
    }

    public function revenueComparison(): array
    {
        return $this->cache->remember('revenue-comparison:'.now()->toDateString(), 120, function (): array {
            $periods = [
                'Hari ini' => new AnalyticsPeriod(now()->startOfDay(), now()->startOfDay(), 'today'),
                '7 hari' => new AnalyticsPeriod(now()->startOfDay()->subDays(6), now()->startOfDay(), '7_days'),
                'Bulan ini' => new AnalyticsPeriod(now()->startOfMonth(), now()->startOfDay(), 'month'),
                'Tahun ini' => new AnalyticsPeriod(now()->startOfYear(), now()->startOfDay(), 'year'),
            ];

            return collect($periods)->map(fn (AnalyticsPeriod $period): int => $this->totalFor($period)['gross_revenue'])->all();
        });
    }

    public function driverSummary(AnalyticsPeriod $period): array
    {
        return $this->cache->remember('driver-summary:'.$period->cacheKey(), 90, function () use ($period): array {
            $totals = DailyDriverAnalytic::query()
                ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->selectRaw('SUM(assigned_orders) as assigned, SUM(accepted_orders) as accepted, SUM(completed_orders) as completed, SUM(cancelled_orders) as cancelled, SUM(rating_sum) as rating_sum, SUM(ratings_count) as ratings_count')
                ->first();

            $assigned = (int) ($totals?->assigned ?? 0);
            $accepted = (int) ($totals?->accepted ?? 0);

            return [
                ...$this->driverAvailability(),
                'completed_orders' => (int) ($totals?->completed ?? 0),
                'acceptance_rate' => $assigned > 0 ? round(($accepted / $assigned) * 100, 1) : 0,
                'cancellation_rate' => $accepted > 0 ? round(((int) ($totals?->cancelled ?? 0) / $accepted) * 100, 1) : 0,
                'average_rating' => (int) ($totals?->ratings_count ?? 0) > 0
                    ? round(((int) $totals->rating_sum / (int) $totals->ratings_count), 2)
                    : 0,
            ];
        });
    }

    public function driverRankingQuery(AnalyticsPeriod $period): Builder
    {
        return DailyDriverAnalytic::query()
            ->with('driver.user')
            ->selectRaw('MIN(id) as id, driver_id, SUM(assigned_orders) as assigned_orders, SUM(accepted_orders) as accepted_orders, SUM(completed_orders) as completed_orders, SUM(cancelled_orders) as cancelled_orders, SUM(gross_revenue) as gross_revenue, SUM(rating_sum) as rating_sum, SUM(ratings_count) as ratings_count')
            ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('driver_id')
            ->orderByRaw('SUM(completed_orders) DESC');
    }

    public function areaTotals(AnalyticsPeriod $period): Collection
    {
        return $this->cache->remember('areas:'.$period->cacheKey(), 120, fn (): Collection => $this->areaQuery($period)->limit(10)->get());
    }

    public function areaQuery(AnalyticsPeriod $period): Builder
    {
        return DailyAreaAnalytic::query()
            ->selectRaw('MIN(id) as id, area_key, MAX(area_name) as area_name')
            ->selectRaw('SUM(orders_count) as orders_count, SUM(completed_orders) as completed_orders, SUM(cancelled_orders) as cancelled_orders, SUM(gross_revenue) as gross_revenue, SUM(platform_fee) as platform_fee')
            ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('area_key')
            ->orderByRaw('SUM(orders_count) DESC');
    }

    public function peakHours(AnalyticsPeriod $period): array
    {
        return $this->cache->remember('peak:'.$period->cacheKey(), 120, function () use ($period): array {
            $byHour = array_fill(0, 24, 0);
            $daily = [];
            $rows = DailyServiceAnalytic::query()
                ->where('is_total', true)
                ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->orderBy('analytics_date')
                ->get(['analytics_date', 'hourly_orders']);

            foreach ($rows as $row) {
                $hours = array_map('intval', $row->hourly_orders ?? array_fill(0, 24, 0));
                $daily[$row->analytics_date->format('d M')] = $hours;
                foreach ($hours as $hour => $value) {
                    $byHour[$hour] += $value;
                }
            }

            arsort($byHour);
            $top = array_slice($byHour, 0, 3, true);
            ksort($byHour);

            return ['hours' => $byHour, 'top' => $top, 'daily' => $daily];
        });
    }

    private function totalFor(AnalyticsPeriod $period): array
    {
        $row = DailyServiceAnalytic::query()
            ->where('is_total', true)
            ->whereBetween('analytics_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('SUM(orders_count) as orders_count, SUM(completed_orders) as completed_orders, SUM(cancelled_orders) as cancelled_orders, SUM(unique_customers) as unique_customers, SUM(repeat_customers) as repeat_customers')
            ->selectRaw('SUM(gross_revenue) as gross_revenue, SUM(net_revenue) as net_revenue, SUM(driver_payout) as driver_payout, SUM(platform_fee) as platform_fee, SUM(rating_sum) as rating_sum, SUM(ratings_count) as ratings_count')
            ->first();

        $ratings = (int) ($row?->ratings_count ?? 0);

        return [
            'orders_count' => (int) ($row?->orders_count ?? 0),
            'completed_orders' => (int) ($row?->completed_orders ?? 0),
            'cancelled_orders' => (int) ($row?->cancelled_orders ?? 0),
            'repeat_customers' => (int) ($row?->repeat_customers ?? 0),
            'gross_revenue' => (int) ($row?->gross_revenue ?? 0),
            'net_revenue' => (int) ($row?->net_revenue ?? 0),
            'driver_payout' => (int) ($row?->driver_payout ?? 0),
            'platform_fee' => (int) ($row?->platform_fee ?? 0),
            'average_rating' => $ratings > 0 ? round(((int) $row->rating_sum / $ratings), 2) : 0,
        ];
    }

    private function driverAvailability(): array
    {
        $active = Driver::query()
            ->whereHas('user', fn (Builder $query): Builder => $query
                ->where('role', UserRole::Driver->value)
                ->where('is_active', true)
                ->where('is_suspended', false))
            ->where('status', DriverStatus::Active->value)
            ->count();

        $online = Driver::query()
            ->where('is_available', true)
            ->where('status', DriverStatus::Active->value)
            ->whereHas('user', fn (Builder $query): Builder => $query->where('is_active', true)->where('is_suspended', false))
            ->count();

        return ['active_drivers' => $active, 'online_drivers' => $online];
    }

    private function trend(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
