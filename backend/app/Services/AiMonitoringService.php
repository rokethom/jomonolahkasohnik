<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiMonitoringService
{
    private const OPENROUTER_MODELS = [
        'openrouter/free',
        'openrouter/auto',
    ];

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function dashboard(): array
    {
        $events = $this->aiLogEvents();
        $recent = collect($events)->take(80);
        $success = $recent->where('event', 'ai_order_parser.model_success')->count();
        $fallback = $recent->filter(fn (array $event): bool => str_contains($event['event'], 'fallback'))->count();
        $failed = $recent->filter(fn (array $event): bool => str_contains($event['event'], 'failed') || str_contains($event['event'], 'exception'))->count();
        $avgLatency = round((float) $recent->avg('response_time_seconds'), 3);

        return [
            'active_status' => [
                'enabled' => $this->settings->bool('ai_assistant_enabled', false),
                'provider' => strtolower((string) $this->settings->get('ai_provider', 'openai')),
                'configured_model' => $this->settings->bool('ai_openrouter_free_auto_enabled', false)
                    ? 'openrouter/free'
                    : ($this->settings->get('ai_model') ?: 'openrouter/auto'),
                'base_url' => $this->settings->get('ai_base_url') ?: 'default provider URL',
                'mode' => 'analysis_only',
            ],
            'analytics' => [
                'recent_requests' => $recent->count(),
                'success_count' => $success,
                'fallback_count' => $fallback,
                'failed_count' => $failed,
                'avg_latency_seconds' => $avgLatency,
                'health' => $failed > 0 && $success === 0 ? 'critical' : ($fallback > 3 || $avgLatency >= 10 ? 'warning' : 'normal'),
            ],
            'model_cards' => $this->monitoredModels()->map(fn (string $model, int $index): array => $this->modelCard($model, $index + 1, $events))->all(),
            'fallback_history' => collect($events)
                ->filter(fn (array $event): bool => str_contains($event['event'], 'fallback') || str_contains($event['event'], 'failed') || str_contains($event['event'], 'exception'))
                ->take(12)
                ->values()
                ->all(),
            'health' => [
                'normal_models' => $this->monitoredModels()->filter(fn (string $model): bool => ! $this->slowModel($model))->count(),
                'slow_models' => $this->monitoredModels()->filter(fn (string $model): bool => (bool) $this->slowModel($model))->count(),
                'log_file' => 'storage/logs/ai.log',
                'last_event_at' => data_get($events, '0.time'),
            ],
        ];
    }

    public function securityDashboard(): array
    {
        if (! $this->driverAuthMonitoringColumnsAvailable()) {
            return [
                'active_status' => [
                    'mode' => 'ai_analysis_only',
                    'firewall_primary' => false,
                    'model_pool' => ['DeepSeek', 'Qwen', 'Gemma'],
                    'auto_blacklist_temporary' => false,
                ],
                'summary' => [
                    'login_anomalies' => 0,
                    'locked_auth' => 0,
                    'spam_candidates' => 0,
                    'bot_candidates' => 0,
                    'temporary_blacklist' => 0,
                    'status' => 'normal',
                ],
                'login_anomalies' => [],
                'temporary_blacklist' => [],
                'suspicious_requests' => [],
                'fake_order_candidates' => [],
            ];
        }

        $failedDrivers = Driver::query()
            ->with('user.branch')
            ->where('auth_failed_attempts', '>', 0)
            ->orderByDesc('auth_failed_attempts')
            ->limit(12)
            ->get();
        $lockedDrivers = $failedDrivers->filter(fn (Driver $driver): bool => $driver->auth_locked_until !== null && $driver->auth_locked_until->isFuture());
        $ipGroups = $failedDrivers
            ->filter(fn (Driver $driver): bool => filled($driver->last_login_ip))
            ->groupBy('last_login_ip');
        $blacklist = $ipGroups
            ->filter(fn ($items): bool => $items->sum('auth_failed_attempts') >= 5 || $items->count() >= 3)
            ->map(function ($items, string $ip): array {
                $payload = [
                    'ip' => $ip,
                    'reason' => 'repeated_driver_login_failure',
                    'score' => min(100, (int) $items->sum('auth_failed_attempts') * 12),
                    'expires_in_minutes' => 15,
                ];
                Cache::put('security_blacklist:'.$ip, $payload, now()->addMinutes(15));

                return $payload;
            })
            ->values();
        $fakeOrderCandidates = $this->fakeOrderCandidates();

        return [
            'active_status' => [
                'mode' => 'ai_analysis_only',
                'firewall_primary' => false,
                'model_pool' => ['DeepSeek', 'Qwen', 'Gemma'],
                'auto_blacklist_temporary' => true,
            ],
            'summary' => [
                'login_anomalies' => $failedDrivers->count(),
                'locked_auth' => $lockedDrivers->count(),
                'spam_candidates' => $fakeOrderCandidates->where('type', 'spam_flood')->count(),
                'bot_candidates' => $fakeOrderCandidates->where('type', 'bot_order')->count(),
                'temporary_blacklist' => $blacklist->count(),
                'status' => $blacklist->isNotEmpty() || $lockedDrivers->count() > 2 ? 'warning' : 'normal',
            ],
            'login_anomalies' => $failedDrivers->map(fn (Driver $driver): array => [
                'driver' => $driver->name ?: $driver->user?->name,
                'email' => $driver->email ?: $driver->user?->email,
                'ip' => $driver->last_login_ip,
                'device' => $driver->last_login_device,
                'failed_attempts' => (int) $driver->auth_failed_attempts,
                'locked_until' => $driver->auth_locked_until?->toDateTimeString(),
                'area' => $driver->user?->branch?->area ?: $driver->user?->branch?->name,
            ])->values()->all(),
            'temporary_blacklist' => $blacklist->all(),
            'suspicious_requests' => $this->suspiciousAuditEvents(),
            'fake_order_candidates' => $fakeOrderCandidates->values()->all(),
        ];
    }

    private function driverAuthMonitoringColumnsAvailable(): bool
    {
        foreach (['auth_failed_attempts', 'auth_locked_until', 'last_login_ip'] as $column) {
            if (! Schema::hasColumn('drivers', $column)) {
                return false;
            }
        }

        return true;
    }

    private function modelCard(string $model, int $priority, array $events): array
    {
        $modelEvents = collect($events)->where('model', $model);
        $slow = $this->slowModel($model);
        $fallbacks = $modelEvents->filter(fn (array $event): bool => str_contains($event['event'], 'fallback'))->count();
        $errors = $modelEvents->filter(fn (array $event): bool => filled($event['error']))->count();

        return [
            'model' => $model,
            'priority' => $priority,
            'status' => $slow ? 'warning' : ($errors > 3 ? 'critical' : 'normal'),
            'success_count' => $modelEvents->where('event', 'ai_order_parser.model_success')->count(),
            'fallback_count' => $fallbacks,
            'avg_latency_seconds' => round((float) $modelEvents->avg('response_time_seconds'), 3),
            'slow_until' => data_get($slow, 'marked_at') ? '10 menit sejak '.data_get($slow, 'marked_at') : null,
        ];
    }

    private function monitoredModels()
    {
        return collect([$this->settings->get('ai_model'), ...self::OPENROUTER_MODELS])
            ->filter()
            ->unique()
            ->values();
    }

    private function aiLogEvents(): array
    {
        $path = storage_path('logs/ai.log');
        if (! File::exists($path)) {
            return [];
        }

        return collect(array_reverse(array_slice(file($path, FILE_IGNORE_NEW_LINES) ?: [], -200)))
            ->map(fn (string $line): ?array => $this->parseAiLogLine($line))
            ->filter()
            ->values()
            ->all();
    }

    private function parseAiLogLine(string $line): ?array
    {
        if (preg_match('/^\[(?<time>[^\]]+)\]\s+\w+\.(?<level>\w+):\s+(?<event>[^\s]+)\s*(?<json>\{.*\})?$/', $line, $match) !== 1) {
            return null;
        }

        $context = filled($match['json'] ?? null) ? json_decode($match['json'], true) : [];
        $context = is_array($context) ? $context : [];

        return [
            'time' => $match['time'],
            'level' => strtolower($match['level']),
            'event' => $match['event'],
            'model' => $context['model'] ?? null,
            'provider' => $context['provider'] ?? null,
            'response_time_seconds' => (float) ($context['response_time_seconds'] ?? 0),
            'fallback_count' => (int) ($context['fallback_count'] ?? 0),
            'error' => $context['error'] ?? null,
        ];
    }

    private function slowModel(string $model): mixed
    {
        try {
            return Cache::store('redis')->get('slow_model:'.$model);
        } catch (Throwable) {
            return Cache::get('slow_model:'.$model);
        }
    }

    private function suspiciousAuditEvents(): array
    {
        return AuditLog::query()
            ->whereIn('action', [
                'driver_google_login_failed_attempt',
                'driver_google_login_unregistered_email',
                'driver_google_login_denied',
                'created_dashboard_text_order',
                'reset_driver_google_bind',
                'suspended_driver_google_auth',
            ])
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'time' => $log->created_at?->toDateTimeString(),
                'action' => $log->action,
                'subject' => $log->subject_label,
                'metadata' => $log->metadata,
            ])
            ->all();
    }

    private function fakeOrderCandidates()
    {
        $recentOrders = Order::query()
            ->with('user')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest()
            ->limit(200)
            ->get();

        $byUser = $recentOrders->whereNotNull('user_id')->groupBy('user_id')
            ->filter(fn ($items): bool => $items->count() >= 5)
            ->map(fn ($items): array => [
                'type' => 'spam_flood',
                'score' => min(100, $items->count() * 15),
                'customer' => $items->first()->user?->name,
                'reason' => $items->count().' order dalam 30 menit',
                'last_order' => $items->first()->order_code,
            ]);

        $sameText = $recentOrders->filter(fn (Order $order): bool => filled($order->raw_text))
            ->groupBy(fn (Order $order): string => md5(strtolower(trim((string) $order->raw_text))))
            ->filter(fn ($items): bool => $items->count() >= 3)
            ->map(fn ($items): array => [
                'type' => 'bot_order',
                'score' => min(100, $items->count() * 20),
                'customer' => $items->first()->user?->name,
                'reason' => 'Teks order identik berulang '.$items->count().' kali',
                'last_order' => $items->first()->order_code,
            ]);

        $cancelled = $recentOrders->filter(fn (Order $order): bool => $order->status === OrderStatus::Cancelled)
            ->groupBy('user_id')
            ->filter(fn ($items, $userId): bool => filled($userId) && $items->count() >= 3)
            ->map(fn ($items): array => [
                'type' => 'fake_order',
                'score' => min(100, $items->count() * 18),
                'customer' => $items->first()->user?->name,
                'reason' => 'Cancel order berulang dalam 30 menit',
                'last_order' => $items->first()->order_code,
            ]);

        return collect([...$byUser->values(), ...$sameText->values(), ...$cancelled->values()])
            ->sortByDesc('score')
            ->take(12)
            ->values();
    }
}
