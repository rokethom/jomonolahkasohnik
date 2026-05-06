<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AdminDashboardMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function productionStats(): array
    {
        $today = now()->startOfDay();
        $ordersToday = Order::query()->where('created_at', '>=', $today);

        return [
            'total_driver_online' => Driver::query()->where('is_available', true)->where('status', 'active')->count(),
            'total_order_today' => (clone $ordersToday)->count(),
            'pending_order' => (clone $ordersToday)->whereIn('status', [
                OrderStatus::Created->value,
                OrderStatus::SearchingDriver->value,
                OrderStatus::PendingCancel->value,
            ])->count(),
            'success_order' => (clone $ordersToday)->where('status', OrderStatus::Completed->value)->count(),
            'failed_order' => (clone $ordersToday)->where('status', OrderStatus::Cancelled->value)->count(),
            'active_customer' => User::query()->where('role', UserRole::Customer->value)->where('is_active', true)->where('is_suspended', false)->count(),
            'active_driver' => User::query()->where('role', UserRole::Driver->value)->where('is_active', true)->where('is_suspended', false)->count(),
            'revenue_today' => (int) (clone $ordersToday)->where('status', OrderStatus::Completed->value)->sum('total_price'),
            'server_status' => $this->serverStatus(),
            'queue_status' => $this->queueStatus(),
            'websocket_status' => $this->websocketStatus(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serverMetrics(): array
    {
        $memory = $this->memoryUsage();
        $storage = $this->storageUsage();
        $load = $this->loadAverage();

        return [
            $this->metric('CPU Usage', $load['percent'], '%', $this->statusFromPercent($load['percent'], 70, 90), $load['label']),
            $this->metric('RAM Usage', $memory['percent'], '%', $this->statusFromPercent($memory['percent'], 75, 90), $memory['label']),
            $this->metric('Storage Usage', $storage['percent'], '%', $this->statusFromPercent($storage['percent'], 80, 92), $storage['label']),
            $this->metric('Disk IO', null, '', 'normal', 'Not sampled'),
            $this->metric('VPS Uptime', null, '', 'normal', $this->uptimeLabel()),
            $this->metric('Load Average', $load['raw'], '', $this->statusFromPercent($load['percent'], 70, 90), $load['label']),
            $this->metric('Network Traffic', null, '', 'normal', 'Monitor via VPS/Cloudflare'),
            $this->metric('Swap Usage', $this->swapUsage()['percent'], '%', $this->statusFromPercent($this->swapUsage()['percent'], 60, 85), $this->swapUsage()['label']),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function endpointHealth(): array
    {
        $latency = $this->databaseLatencyMs();
        $log = $this->logCounters();

        return [
            ['label' => 'API response time', 'value' => $latency.' ms', 'status' => $latency > 800 ? 'critical' : ($latency > 300 ? 'warning' : 'normal')],
            ['label' => 'API uptime', 'value' => $this->serverStatus(), 'status' => $this->serverStatus()],
            ['label' => 'Failed request', 'value' => (string) $log['failed'], 'status' => $log['failed'] > 20 ? 'critical' : ($log['failed'] > 5 ? 'warning' : 'normal')],
            ['label' => '4xx count', 'value' => (string) $log['4xx'], 'status' => $log['4xx'] > 50 ? 'warning' : 'normal'],
            ['label' => '5xx count', 'value' => (string) $log['5xx'], 'status' => $log['5xx'] > 0 ? 'critical' : 'normal'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function timeline(): array
    {
        $log = $this->logCounters();
        $systemItems = collect([
            [
                'title' => 'Server Health Check',
                'subtitle' => 'Status '.$this->serverStatus(),
                'time' => 'realtime',
                'status' => $this->serverStatus(),
            ],
            [
                'title' => 'Queue Status',
                'subtitle' => config('queue.default').' queue',
                'time' => 'realtime',
                'status' => $this->queueStatus(),
            ],
            [
                'title' => 'WebSocket Status',
                'subtitle' => config('broadcasting.default').' broadcast',
                'time' => 'realtime',
                'status' => $this->websocketStatus(),
            ],
        ]);

        if ($log['5xx'] > 0) {
            $systemItems->push([
                'title' => 'API Error',
                'subtitle' => $log['5xx'].' server error entries in recent log',
                'time' => 'recent',
                'status' => 'critical',
            ]);
        }

        $auditItems = AuditLog::query()
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'title' => $this->humanAction($log->action),
                'subtitle' => $log->subject_label ?: $log->subject_type,
                'time' => $log->created_at?->diffForHumans() ?? '-',
                'status' => str_contains($log->action, 'failed') || str_contains($log->action, 'error') ? 'critical' : (str_contains($log->action, 'restart') ? 'warning' : 'normal'),
            ]);

        return $systemItems
            ->merge($auditItems)
            ->take(16)
            ->values()
            ->all();
    }

    /**
     * @return array{labels: array<int, string>, orders: array<int, int>, revenue: array<int, int>, active_drivers: array<int, int>, latency: array<int, int>, cpu: array<int, int>, ram: array<int, int>, storage: array<int, int>}
     */
    public function chartSeries(): array
    {
        $labels = [];
        $orders = [];
        $revenue = [];
        $activeDrivers = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $start = now()->startOfDay()->addHours($hour);
            $end = (clone $start)->addHour();

            $labels[] = $start->format('H:00');
            $orders[] = Order::query()->whereBetween('created_at', [$start, $end])->count();
            $revenue[] = (int) Order::query()->where('status', OrderStatus::Completed->value)->whereBetween('created_at', [$start, $end])->sum('total_price');
            $activeDrivers[] = Driver::query()->where('is_available', true)->where('updated_at', '<=', $end)->count();
        }

        $latency = $this->databaseLatencyMs();
        $cpu = (int) $this->loadAverage()['percent'];
        $ram = (int) $this->memoryUsage()['percent'];
        $storage = (int) $this->storageUsage()['percent'];

        return [
            'labels' => $labels,
            'orders' => $orders,
            'revenue' => $revenue,
            'active_drivers' => $activeDrivers,
            'latency' => array_fill(0, 24, $latency),
            'cpu' => array_fill(0, 24, $cpu),
            'ram' => array_fill(0, 24, $ram),
            'storage' => array_fill(0, 24, $storage),
        ];
    }

    public function databaseLatencyMs(): int
    {
        $start = microtime(true);
        DB::select('select 1');

        return (int) round((microtime(true) - $start) * 1000);
    }

    private function serverStatus(): string
    {
        try {
            DB::select('select 1');

            return $this->storageUsage()['percent'] >= 92 ? 'critical' : 'normal';
        } catch (\Throwable) {
            return 'critical';
        }
    }

    private function queueStatus(): string
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            return 'warning';
        }

        return 'normal';
    }

    private function websocketStatus(): string
    {
        return config('broadcasting.default') === 'reverb' || env('REVERB_APP_ID') ? 'normal' : 'warning';
    }

    /**
     * @return array{percent: int, label: string}
     */
    private function memoryUsage(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && File::exists('/proc/meminfo')) {
            $info = File::get('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $info, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $info, $available);

            if (($total[1] ?? 0) > 0) {
                $used = (int) $total[1] - (int) ($available[1] ?? 0);
                $percent = (int) round(($used / (int) $total[1]) * 100);

                return ['percent' => $percent, 'label' => $percent.'% used'];
            }
        }

        $memory = memory_get_usage(true);
        $limit = $this->bytesFromPhpIni(ini_get('memory_limit'));
        $percent = $limit > 0 ? min(100, (int) round(($memory / $limit) * 100)) : 0;

        return ['percent' => $percent, 'label' => $percent ? $percent.'% PHP memory' : 'Unavailable'];
    }

    /**
     * @return array{percent: int, label: string}
     */
    private function swapUsage(): array
    {
        if (PHP_OS_FAMILY === 'Linux' && File::exists('/proc/meminfo')) {
            $info = File::get('/proc/meminfo');
            preg_match('/SwapTotal:\s+(\d+)/', $info, $total);
            preg_match('/SwapFree:\s+(\d+)/', $info, $free);

            if (($total[1] ?? 0) > 0) {
                $used = (int) $total[1] - (int) ($free[1] ?? 0);
                $percent = (int) round(($used / (int) $total[1]) * 100);

                return ['percent' => $percent, 'label' => $percent.'% used'];
            }
        }

        return ['percent' => 0, 'label' => 'Unavailable'];
    }

    /**
     * @return array{percent: int, label: string}
     */
    private function storageUsage(): array
    {
        $path = base_path();
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;

        if ($total <= 0) {
            return ['percent' => 0, 'label' => 'Unavailable'];
        }

        $percent = (int) round((($total - $free) / $total) * 100);

        return ['percent' => $percent, 'label' => $percent.'% used'];
    }

    /**
     * @return array{raw: string, percent: int, label: string}
     */
    private function loadAverage(): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;
        $cores = max(1, (int) env('SERVER_CPU_CORES', 2));
        $percent = min(100, (int) round(($load / $cores) * 100));

        return ['raw' => number_format($load, 2), 'percent' => $percent, 'label' => number_format($load, 2).' / '.$cores.' cores'];
    }

    private function uptimeLabel(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && File::exists('/proc/uptime')) {
            $seconds = (int) floor((float) explode(' ', File::get('/proc/uptime'))[0]);

            return Carbon::now()->subSeconds($seconds)->diffForHumans(null, true).' uptime';
        }

        return 'Unavailable locally';
    }

    /**
     * @return array{failed: int, 4xx: int, 5xx: int}
     */
    private function logCounters(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            return ['failed' => 0, '4xx' => 0, '5xx' => 0];
        }

        $content = substr(File::get($path), -250000);

        return [
            'failed' => substr_count(strtolower($content), 'failed'),
            '4xx' => preg_match_all('/\b4\d{2}\b/', $content),
            '5xx' => preg_match_all('/\b5\d{2}\b/', $content),
        ];
    }

    private function statusFromPercent(?int $percent, int $warning, int $critical): string
    {
        if ($percent === null) {
            return 'normal';
        }

        return $percent >= $critical ? 'critical' : ($percent >= $warning ? 'warning' : 'normal');
    }

    /**
     * @return array{label: string, value: int|string|null, suffix: string, status: string, detail: string}
     */
    private function metric(string $label, int|string|null $value, string $suffix, string $status, string $detail): array
    {
        return compact('label', 'value', 'suffix', 'status', 'detail');
    }

    private function bytesFromPhpIni(string|false $value): int
    {
        if ($value === false || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function humanAction(string $action): string
    {
        return str($action)
            ->replace('_', ' ')
            ->title()
            ->toString();
    }
}
