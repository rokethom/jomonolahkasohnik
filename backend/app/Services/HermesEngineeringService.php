<?php

namespace App\Services;

use App\Models\HermesReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HermesEngineeringService
{
    private const MAX_TOTAL_CONTEXT = 70000;

    public function __construct(
        private readonly SettingService $settings,
        private readonly AdminDashboardMetricsService $metrics,
    ) {
    }

    /**
     * @return array<string, array{label: string, description: string, files: array<int, string>, focus: array<int, string>}>
     */
    public function commandOptions(): array
    {
        return [
            'system_overview' => [
                'label' => 'Full system overview',
                'description' => 'Baca arsitektur inti, route, schema, metrics, dan log terbaru.',
                'files' => $this->coreFiles(),
                'focus' => ['architecture', 'flow order', 'API surface', 'runtime health', 'security risk'],
            ],
            'scan_order_module' => [
                'label' => 'Scan order module',
                'description' => 'Analisa parser order, pricing, create order, assign driver, dan risiko race condition.',
                'files' => [
                    'app/Services/OrderService.php',
                    'app/Services/OrderParserService.php',
                    'app/Services/AiOrderParserService.php',
                    'app/Services/PricingService.php',
                    'app/Services/OrderOperationService.php',
                    'app/Http/Controllers/Api/OrderController.php',
                    'app/Models/Order.php',
                    'routes/api.php',
                ],
                'focus' => ['order lifecycle', 'pricing', 'race condition', 'duplicate order', 'dispatch logic'],
            ],
            'analyze_websocket_performance' => [
                'label' => 'Analyze websocket performance',
                'description' => 'Review broadcast/realtime, live chat, order update, dan risiko disconnect.',
                'files' => [
                    'config/broadcasting.php',
                    'config/reverb.php',
                    'app/Services/ChatService.php',
                    'app/Services/NotificationService.php',
                    'routes/channels.php',
                    'routes/api.php',
                ],
                'focus' => ['websocket health', 'broadcast payload', 'realtime latency', 'fallback strategy'],
            ],
            'check_security_vulnerabilities' => [
                'label' => 'Check security vulnerabilities',
                'description' => 'Cari risiko auth, policy, token, file upload, spoofing, dan data leakage.',
                'files' => [
                    'app/Http/Controllers/Api/AuthController.php',
                    'app/Services/DriverGoogleAuthService.php',
                    'app/Providers/RouteServiceProvider.php',
                    'routes/api.php',
                    'app/Models/User.php',
                    'app/Models/Driver.php',
                ],
                'focus' => ['auth security', 'Sanctum', 'Google token verification', 'rate limit', 'role access'],
            ],
            'review_driver_api' => [
                'label' => 'Review driver API',
                'description' => 'Analisa flow driver, Google login, order list, status online, rating, dan performa.',
                'files' => [
                    'app/Services/DriverGoogleAuthService.php',
                    'app/Services/DriverFinanceService.php',
                    'app/Services/RatingService.php',
                    'app/Models/Driver.php',
                    'routes/api.php',
                ],
                'focus' => ['driver auth', 'driver order visibility', 'online state', 'rating feedback', 'performance data'],
            ],
            'detect_unused_services' => [
                'label' => 'Detect unused services',
                'description' => 'Beri kandidat service/helper yang tampak redundan atau perlu dirapikan.',
                'files' => $this->serviceFiles(),
                'focus' => ['dead code candidates', 'duplicated logic', 'service boundaries', 'naming consistency'],
            ],
            'find_slow_queries' => [
                'label' => 'Find slow queries',
                'description' => 'Review model, schema, dashboard query, index risk, dan pola N+1.',
                'files' => [
                    'app/Services/AdminDashboardMetricsService.php',
                    'app/Filament/Resources/OrderResource.php',
                    'app/Filament/Resources/DriverManagementResource.php',
                    'app/Models/Order.php',
                    'app/Models/Driver.php',
                ],
                'focus' => ['slow query', 'N+1', 'missing indexes', 'dashboard aggregation', 'pagination'],
            ],
            'generate_caching_strategy' => [
                'label' => 'Generate caching strategy',
                'description' => 'Rancang cache untuk dashboard, parser, route-heavy data, dan monitoring.',
                'files' => $this->coreFiles(),
                'focus' => ['cache keys', 'TTL', 'invalidation', 'Redis fallback', 'stale data risk'],
            ],
            'review_ai_gateway_architecture' => [
                'label' => 'Review AI gateway architecture',
                'description' => 'Review AI parser, fallback OpenRouter, AI monitoring, security analytics, dan Hermes boundary.',
                'files' => [
                    'app/Services/AiOrderParserService.php',
                    'app/Services/AiMonitoringService.php',
                    'app/Services/AiParserRuleService.php',
                    'app/Filament/Pages/AiMonitoringPage.php',
                    'app/Filament/Pages/SystemSettingsPage.php',
                ],
                'focus' => ['AI routing', 'fallback policy', 'prompt safety', 'observability', 'cost and latency'],
            ],
            'analyze_logs' => [
                'label' => 'Analyze logs and failures',
                'description' => 'Klasifikasi error dari laravel.log, ai.log, hermes.log, dan failed_jobs.',
                'files' => [],
                'focus' => ['exception pattern', 'root cause', 'operational fix', 'risk level', 'next commands'],
            ],
            'database_schema_review' => [
                'label' => 'Database schema review',
                'description' => 'Review tabel inti, relasi, index risk, dan data operasional.',
                'files' => [
                    'database/migrations/2026_05_01_000003_create_orders_table.php',
                    'database/migrations/2026_05_01_000002_create_drivers_table.php',
                    'database/migrations/2026_05_01_000011_create_chat_tables.php',
                    'database/migrations/2026_05_07_000001_create_customer_driver_preferences_table.php',
                ],
                'focus' => ['schema design', 'index risk', 'data consistency', 'auditability', 'scaling'],
            ],
        ];
    }

    /**
     * @return array{enabled: bool, model: mixed, base_url: mixed, reports_count: int, latest_report: ?HermesReport}
     */
    public function status(): array
    {
        return [
            'enabled' => $this->settings->bool('hermes_enabled', false),
            'provider' => $this->settings->get('hermes_provider', 'openai_compatible'),
            'model' => $this->model(),
            'base_url' => $this->baseUrl() ?: 'Belum diset',
            'reports_count' => HermesReport::query()->count(),
            'latest_report' => HermesReport::query()->latest()->first(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $command, ?string $instruction, ?User $actor): array
    {
        $started = microtime(true);
        $options = $this->commandOptions();
        $command = array_key_exists($command, $options) ? $command : 'system_overview';
        $model = $this->model();
        $report = new HermesReport([
            'user_id' => $actor?->id,
            'command' => $command,
            'instruction' => $instruction,
            'model' => $model,
            'status' => 'running',
        ]);
        $report->save();

        try {
            if (! $this->settings->bool('hermes_enabled', false)) {
                return $this->failReport($report, $started, 'Hermes belum aktif. Aktifkan di System Settings > AI Assistant > Hermes Engineering Assistant.');
            }

            $apiKey = $this->apiKey();
            $baseUrl = $this->baseUrl();

            if (! filled($apiKey) || ! filled($baseUrl) || ! filled($model)) {
                return $this->failReport($report, $started, 'Konfigurasi Hermes belum lengkap. Isi API key, base URL, dan model Hermes.');
            }

            $context = $this->buildContext($command, $options[$command]);
            $prompt = $this->prompt($options[$command], $instruction, $context);

            $response = Http::withToken((string) $apiKey)
                ->acceptJson()
                ->withHeaders($this->headers())
                ->connectTimeout(8)
                ->timeout(90)
                ->post(rtrim((string) $baseUrl, '/').'/chat/completions', $this->chatPayload($model, [
                    [
                        'role' => 'system',
                        'content' => 'You are Hermes, JojoApp internal AI engineering assistant. You are read-only. Analyze code/log/schema context and produce practical engineering reports in Indonesian. Never claim you executed shell commands or changed files.',
                    ],
                    ['role' => 'user', 'content' => $prompt],
                ]));

            $duration = (int) round((microtime(true) - $started) * 1000);

            if (! $response->successful()) {
                return $this->failReport($report, $started, 'Hermes API gagal: HTTP '.$response->status().' '.$response->body());
            }

            $content = data_get($response->json(), 'choices.0.message.content');
            if (! filled($content)) {
                return $this->failReport($report, $started, 'Hermes API memberi response kosong.');
            }

            $report->forceFill([
                'status' => 'completed',
                'duration_ms' => $duration,
                'context_summary' => $context['summary'],
                'report' => (string) $content,
                'error_message' => null,
            ])->save();

            Log::channel('hermes')->info('hermes.report.completed', [
                'command' => $command,
                'model' => $model,
                'duration_ms' => $duration,
                'actor_id' => $actor?->id,
            ]);

            return [
                'success' => true,
                'message' => 'Hermes selesai menganalisa sistem.',
                'report' => $report->fresh(),
            ];
        } catch (Throwable $exception) {
            return $this->failReport($report, $started, $exception->getMessage());
        }
    }

    /**
     * @return array<int, HermesReport>
     */
    public function recentReports(int $limit = 8): array
    {
        return HermesReport::query()
            ->with('user:id,name,email')
            ->latest()
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param array{label: string, description: string, files: array<int, string>, focus: array<int, string>} $profile
     * @return array{summary: array<string, mixed>, payload: array<string, mixed>}
     */
    private function buildContext(string $command, array $profile): array
    {
        $payload = [
            'command' => $command,
            'focus' => $profile['focus'],
            'routes' => $this->routesSummary(),
            'database_schema' => $this->databaseSchema(),
            'server_metrics' => [
                'production' => $this->safeCall(fn () => $this->metrics->productionStats()),
                'endpoint_health' => $this->safeCall(fn () => $this->metrics->endpointHealth()),
                'server' => $this->safeCall(fn () => $this->metrics->serverMetrics()),
            ],
            'failed_jobs' => $this->failedJobs(),
            'logs' => $this->logsSummary(),
            'source_files' => $this->sourceFiles($profile['files']),
        ];

        return [
            'summary' => [
                'command' => $command,
                'focus' => $profile['focus'],
                'routes_count' => count($payload['routes']),
                'tables_count' => count($payload['database_schema']),
                'source_files_count' => count($payload['source_files']),
                'log_files' => array_keys($payload['logs']),
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array{label: string, description: string, files: array<int, string>, focus: array<int, string>} $profile
     * @param array{summary: array<string, mixed>, payload: array<string, mixed>} $context
     */
    private function prompt(array $profile, ?string $instruction, array $context): string
    {
        $json = json_encode($context['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = mb_substr((string) $json, 0, $this->maxTotalContext());

        return implode("\n\n", [
            'Mode: Internal AI Engineering Assistant / AI DevOps Agent untuk JojoApp.',
            'Command: '.$profile['label'],
            'Deskripsi: '.$profile['description'],
            'Instruksi tambahan dari admin: '.(filled($instruction) ? $instruction : '-'),
            'Batasan keamanan: jangan minta secret, jangan menyarankan bypass auth, jangan mengklaim menjalankan command, jangan mengubah file. Jika memberi code, berikan patch/saran terarah saja.',
            'Output wajib dalam Bahasa Indonesia dengan format:',
            '1. Ringkasan eksekutif',
            '2. Temuan prioritas tinggi',
            '3. Risiko bug/security/performance',
            '4. Rekomendasi fix bertahap',
            '5. Query/cache/index yang disarankan bila relevan',
            '6. Next command Hermes yang disarankan',
            'Context JSON:',
            $json,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function routesSummary(): array
    {
        return collect(Route::getRoutes())
            ->map(fn ($route): array => [
                'methods' => implode('|', array_diff($route->methods(), ['HEAD'])),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'middleware' => $route->gatherMiddleware(),
                'action' => is_string($route->getActionName()) ? $route->getActionName() : 'closure',
            ])
            ->filter(fn (array $route): bool => str_starts_with((string) $route['uri'], 'api') || str_starts_with((string) $route['uri'], 'admin'))
            ->take(180)
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function databaseSchema(): array
    {
        $tables = [
            'users',
            'drivers',
            'orders',
            'order_items',
            'payments',
            'branches',
            'chat_conversations',
            'chat_messages',
            'ratings',
            'audit_logs',
            'app_settings',
            'ai_parser_rules',
            'hermes_reports',
            'failed_jobs',
        ];

        $schema = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $schema[$table] = Schema::getColumnListing($table);
            }
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    private function logsSummary(): array
    {
        return [
            'laravel.log' => $this->tailFile(storage_path('logs/laravel.log')),
            'ai.log' => $this->tailFile(storage_path('logs/ai.log')),
            'hermes.log' => $this->tailFile(storage_path('logs/hermes.log')),
            'nginx_error.log' => $this->tailExisting([
                '/var/log/nginx/error.log',
                '/usr/local/lsws/logs/error.log',
                '/usr/local/lsws/logs/stderr.log',
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function failedJobs(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return [];
            }

            return DB::table('failed_jobs')
                ->latest('failed_at')
                ->limit(8)
                ->get(['uuid', 'connection', 'queue', 'exception', 'failed_at'])
                ->map(fn ($job): array => [
                    'uuid' => $job->uuid,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception' => mb_substr((string) $job->exception, 0, 1200),
                ])
                ->all();
        } catch (Throwable $exception) {
            return [['error' => $exception->getMessage()]];
        }
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, string>
     */
    private function sourceFiles(array $paths): array
    {
        $files = [];

        foreach (array_unique($paths) as $path) {
            $fullPath = base_path($path);
            if (! File::exists($fullPath) || File::isDirectory($fullPath)) {
                continue;
            }

            $content = File::get($fullPath);
            $files[$path] = mb_substr($content, 0, 9000);
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function coreFiles(): array
    {
        return [
            'routes/api.php',
            'routes/web.php',
            'app/Models/User.php',
            'app/Models/Driver.php',
            'app/Models/Order.php',
            'app/Services/OrderService.php',
            'app/Services/OrderParserService.php',
            'app/Services/AiOrderParserService.php',
            'app/Services/AdminDashboardMetricsService.php',
            'app/Services/SettingService.php',
            'app/Providers/RouteServiceProvider.php',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function serviceFiles(): array
    {
        return collect(File::files(app_path('Services')))
            ->map(fn ($file): string => 'app/Services/'.$file->getFilename())
            ->take(60)
            ->values()
            ->all();
    }

    private function tailExisting(array $paths): string
    {
        foreach ($paths as $path) {
            $tail = $this->tailFile($path);
            if ($tail !== '') {
                return $tail;
            }
        }

        return '';
    }

    private function tailFile(string $path, int $bytes = 22000): string
    {
        if (! File::exists($path) || File::isDirectory($path)) {
            return '';
        }

        $size = File::size($path);
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return '';
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return mb_substr($content, -$bytes);
    }

    private function baseUrl(): ?string
    {
        $provider = (string) $this->settings->get('hermes_provider', 'openai_compatible');
        $custom = $this->settings->get('hermes_base_url');
        if (filled($custom)) {
            return (string) $custom;
        }

        return match ($provider) {
            'openrouter' => 'https://openrouter.ai/api/v1',
            'kimi' => 'https://konektika.web.id/v1',
            'openai' => 'https://api.openai.com/v1',
            'openclaw' => (string) config('services.hermes_safety.openclaw_base_url', ''),
            default => null,
        };
    }

    private function apiKey(): mixed
    {
        if ($this->settings->get('hermes_provider') === 'kimi') {
            return $this->settings->get('kimi_api_key') ?: $this->settings->get('hermes_api_key');
        }

        return $this->settings->get('hermes_api_key');
    }

    private function model(): string
    {
        $model = trim((string) $this->settings->get('hermes_model', ''));

        if ($this->settings->get('hermes_provider') === 'kimi' && ($model === '' || str_starts_with($model, 'nousresearch/'))) {
            return 'moonshot-v1-8k';
        }

        if ($this->settings->get('hermes_provider') === 'openclaw' && ($model === '' || str_starts_with($model, 'nousresearch/'))) {
            return 'openclaw/default';
        }

        return $model !== '' ? $model : 'nousresearch/hermes-3-llama-3.1-405b';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        if ($this->settings->get('hermes_provider') !== 'openrouter') {
            return [];
        }

        return [
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'JojoApp Hermes Engineering Center',
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function chatPayload(string $model, array $messages): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (! $this->requiresMinimalChatPayload()) {
            $payload['temperature'] = 0.2;
            $payload['max_tokens'] = $this->maxTokens();
        }

        return $payload;
    }

    private function requiresMinimalChatPayload(): bool
    {
        return $this->settings->get('hermes_provider') === 'kimi';
    }

    private function maxTotalContext(): int
    {
        if ($this->requiresMinimalChatPayload()) {
            return 24000;
        }

        return self::MAX_TOTAL_CONTEXT;
    }

    private function maxTokens(): int
    {
        return max(600, min(4000, $this->settings->int('hermes_max_tokens', 1800)));
    }

    private function failReport(HermesReport $report, float $started, string $message): array
    {
        $duration = (int) round((microtime(true) - $started) * 1000);

        $report->forceFill([
            'status' => 'failed',
            'duration_ms' => $duration,
            'error_message' => mb_substr($message, 0, 5000),
        ])->save();

        Log::channel('hermes')->warning('hermes.report.failed', [
            'command' => $report->command,
            'model' => $report->model,
            'duration_ms' => $duration,
            'error' => $message,
        ]);

        return [
            'success' => false,
            'message' => $message,
            'report' => $report->fresh(),
        ];
    }

    private function safeCall(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }
}
