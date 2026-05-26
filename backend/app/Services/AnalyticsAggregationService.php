<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\DailyAreaAnalytic;
use App\Models\DailyDriverAnalytic;
use App\Models\DailyServiceAnalytic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsAggregationService
{
    public function __construct(private readonly AnalyticsCacheService $cache) {}

    public function aggregateRange(Carbon $from, Carbon $to): int
    {
        $processed = 0;

        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            $this->aggregateDay($date);
            $processed++;
        }

        $this->cache->flush();

        return $processed;
    }

    public function aggregateDay(Carbon $date): void
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();
        $day = $date->toDateString();

        DB::transaction(function () use ($start, $end, $day): void {
            DailyServiceAnalytic::query()->whereDate('analytics_date', $day)->delete();
            DailyDriverAnalytic::query()->whereDate('analytics_date', $day)->delete();
            DailyAreaAnalytic::query()->whereDate('analytics_date', $day)->delete();

            $this->aggregateServices($start, $end, $day);
            $this->aggregateDrivers($start, $end, $day);
            $this->aggregateAreas($start, $end, $day);
        });
    }

    private function aggregateServices(Carbon $start, Carbon $end, string $day): void
    {
        $serviceCodeExpression = "COALESCE(NULLIF(o.service_code, ''), UPPER(LEFT(o.service_type, 32)), 'LAINNYA')";
        $rows = DB::table('orders as o')
            ->leftJoin('ratings as r', 'r.order_id', '=', 'o.id')
            ->whereBetween('o.created_at', [$start, $end])
            ->selectRaw($serviceCodeExpression.' as service_code')
            ->selectRaw('MAX(o.service_type) as service_name')
            ->selectRaw('COUNT(o.id) as orders_count')
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as completed_orders', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('COUNT(DISTINCT o.user_id) as unique_customers')
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.total_price ELSE 0 END) as gross_revenue', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.service_charge ELSE 0 END) as platform_fee', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN GREATEST(o.total_price - o.service_charge, 0) ELSE 0 END) as driver_payout', [OrderStatus::Completed->value])
            ->selectRaw('COALESCE(SUM(r.rating), 0) as rating_sum')
            ->selectRaw('COUNT(r.id) as ratings_count')
            ->groupByRaw($serviceCodeExpression)
            ->get();

        foreach ($rows as $row) {
            DailyServiceAnalytic::query()->create($this->servicePayload($day, $row));
        }

        $total = DB::table('orders as o')
            ->leftJoin('ratings as r', 'r.order_id', '=', 'o.id')
            ->whereBetween('o.created_at', [$start, $end])
            ->selectRaw('COUNT(o.id) as orders_count')
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as completed_orders', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('COUNT(DISTINCT o.user_id) as unique_customers')
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.total_price ELSE 0 END) as gross_revenue', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.service_charge ELSE 0 END) as platform_fee', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN GREATEST(o.total_price - o.service_charge, 0) ELSE 0 END) as driver_payout', [OrderStatus::Completed->value])
            ->selectRaw('COALESCE(SUM(r.rating), 0) as rating_sum')
            ->selectRaw('COUNT(r.id) as ratings_count')
            ->first();

        $repeatCustomers = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $hours = array_fill(0, 24, 0);
        DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('HOUR(created_at) as order_hour, COUNT(*) as orders_count')
            ->groupBy('order_hour')
            ->get()
            ->each(function (object $row) use (&$hours): void {
                $hours[(int) $row->order_hour] = (int) $row->orders_count;
            });

        DailyServiceAnalytic::query()->create([
            ...$this->servicePayload($day, $total, '__ALL__', 'Semua Layanan'),
            'is_total' => true,
            'repeat_customers' => $repeatCustomers,
            'hourly_orders' => $hours,
        ]);
    }

    private function aggregateDrivers(Carbon $start, Carbon $end, string $day): void
    {
        DB::table('orders as o')
            ->leftJoin('ratings as r', 'r.order_id', '=', 'o.id')
            ->whereNotNull('o.driver_id')
            ->whereBetween('o.created_at', [$start, $end])
            ->groupBy('o.driver_id')
            ->selectRaw('o.driver_id')
            ->selectRaw('COUNT(o.id) as assigned_orders')
            ->selectRaw('SUM(CASE WHEN o.status NOT IN (?, ?) THEN 1 ELSE 0 END) as accepted_orders', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as completed_orders', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.total_price ELSE 0 END) as gross_revenue', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN GREATEST(o.total_price - o.service_charge, 0) ELSE 0 END) as driver_payout', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.service_charge ELSE 0 END) as platform_fee', [OrderStatus::Completed->value])
            ->selectRaw('COALESCE(SUM(r.rating), 0) as rating_sum, COUNT(r.id) as ratings_count')
            ->get()
            ->each(fn (object $row) => DailyDriverAnalytic::query()->create([
                'analytics_date' => $day,
                'driver_id' => $row->driver_id,
                'assigned_orders' => $row->assigned_orders,
                'accepted_orders' => $row->accepted_orders,
                'completed_orders' => $row->completed_orders,
                'cancelled_orders' => $row->cancelled_orders,
                'gross_revenue' => $row->gross_revenue,
                'driver_payout' => $row->driver_payout,
                'platform_fee' => $row->platform_fee,
                'rating_sum' => $row->rating_sum,
                'ratings_count' => $row->ratings_count,
            ]));
    }

    private function aggregateAreas(Carbon $start, Carbon $end, string $day): void
    {
        DB::table('orders as o')
            ->leftJoin('areas as a', 'a.id', '=', 'o.area_id')
            ->leftJoin('branches as b', 'b.id', '=', 'o.branch_id')
            ->whereBetween('o.created_at', [$start, $end])
            ->groupBy('o.area_id', 'o.branch_id', 'a.name', 'b.area', 'b.name')
            ->selectRaw("CASE WHEN o.area_id IS NOT NULL THEN CONCAT('area:', o.area_id) WHEN o.branch_id IS NOT NULL THEN CONCAT('branch:', o.branch_id) ELSE 'unknown' END as area_key")
            ->selectRaw('o.area_id, o.branch_id, COALESCE(a.name, b.area, b.name, ?) as area_name', ['Tanpa Area'])
            ->selectRaw('COUNT(o.id) as orders_count')
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as completed_orders', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.total_price ELSE 0 END) as gross_revenue', [OrderStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN o.status = ? THEN o.service_charge ELSE 0 END) as platform_fee', [OrderStatus::Completed->value])
            ->get()
            ->each(fn (object $row) => DailyAreaAnalytic::query()->create([
                'analytics_date' => $day,
                'area_key' => $row->area_key,
                'area_id' => $row->area_id,
                'branch_id' => $row->branch_id,
                'area_name' => $row->area_name,
                'orders_count' => $row->orders_count,
                'completed_orders' => $row->completed_orders,
                'cancelled_orders' => $row->cancelled_orders,
                'gross_revenue' => $row->gross_revenue,
                'platform_fee' => $row->platform_fee,
            ]));
    }

    private function servicePayload(string $day, ?object $row, ?string $code = null, ?string $name = null): array
    {
        return [
            'analytics_date' => $day,
            'service_code' => $code ?? $row?->service_code ?? 'LAINNYA',
            'service_name' => $name ?? $row?->service_name ?? 'Lainnya',
            'orders_count' => (int) ($row?->orders_count ?? 0),
            'completed_orders' => (int) ($row?->completed_orders ?? 0),
            'cancelled_orders' => (int) ($row?->cancelled_orders ?? 0),
            'unique_customers' => (int) ($row?->unique_customers ?? 0),
            'repeat_customers' => 0,
            'gross_revenue' => (int) ($row?->gross_revenue ?? 0),
            'net_revenue' => (int) ($row?->platform_fee ?? 0),
            'driver_payout' => (int) ($row?->driver_payout ?? 0),
            'platform_fee' => (int) ($row?->platform_fee ?? 0),
            'rating_sum' => (int) ($row?->rating_sum ?? 0),
            'ratings_count' => (int) ($row?->ratings_count ?? 0),
        ];
    }
}
