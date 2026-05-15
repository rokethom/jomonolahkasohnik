<?php

namespace App\Services;

use App\Models\HermesReport;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HermesSafetyAssistantService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {
    }

    public function enabled(): bool
    {
        if ($this->isChatCompletionProvider()) {
            return ((bool) config('services.hermes_safety.enabled') || $this->settings->bool('hermes_enabled', false))
                && filled($this->chatApiKey())
                && filled($this->chatBaseUrl());
        }

        return (bool) config('services.hermes_safety.enabled')
            && filled(config('services.hermes_safety.url'))
            && filled(config('services.hermes_safety.key'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function analyzeError(array $payload, string $command = 'safety_analyze_error'): ?HermesReport
    {
        return $this->send('/analyze-error', $payload, $command);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function suggestFix(array $payload, string $command = 'safety_suggest_fix'): ?HermesReport
    {
        return $this->send('/suggest-fix', $payload, $command);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $endpoint, array $payload, string $command): ?HermesReport
    {
        if (! $this->enabled()) {
            return null;
        }

        $started = microtime(true);
        $report = HermesReport::query()->create([
            'command' => $command,
            'instruction' => $payload['error_message'] ?? $payload['source'] ?? null,
            'model' => 'Hermes Helper Safety Assistant',
            'status' => 'running',
            'context_summary' => [
                'source' => $payload['source'] ?? null,
                'service' => $payload['service'] ?? null,
                'environment' => $payload['environment'] ?? null,
                'endpoint' => $endpoint,
            ],
        ]);

        try {
            if ($this->isChatCompletionProvider()) {
                return $this->sendChatCompletion($report, $started, $payload, $command);
            }

            $response = Http::acceptJson()
                ->withHeaders(['X-Hermes-Key' => (string) config('services.hermes_safety.key')])
                ->connectTimeout(5)
                ->timeout((int) config('services.hermes_safety.timeout', 20))
                ->post(rtrim((string) config('services.hermes_safety.url'), '/').$endpoint, $payload)
                ->throw()
                ->json();

            $report->forceFill([
                'status' => 'completed',
                'duration_ms' => $this->durationMs($started),
                'context_summary' => array_merge($report->context_summary ?? [], [
                    'confidence' => data_get($response, 'confidence'),
                    'safe_actions_count' => count($this->actionList(data_get($response, 'safe_actions', []))),
                    'review_actions_count' => count($this->actionList(data_get($response, 'review_actions', []))),
                    'risky_actions_count' => count($this->actionList(data_get($response, 'risky_actions', []))),
                ]),
                'report' => $this->formatReport($response),
                'error_message' => null,
            ])->save();

            Log::channel('hermes')->info('hermes_safety.completed', [
                'command' => $command,
                'duration_ms' => $report->duration_ms,
                'source' => $payload['source'] ?? null,
            ]);

            return $report->fresh();
        } catch (RequestException $exception) {
            return $this->fail($report, $started, 'Hermes Safety HTTP error: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($report, $started, 'Hermes Safety error: '.$exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendChatCompletion(HermesReport $report, float $started, array $payload, string $command): ?HermesReport
    {
        $provider = $this->provider();

        try {
            $response = Http::withToken((string) $this->chatApiKey())
                ->acceptJson()
                ->withHeaders($this->chatHeaders())
                ->connectTimeout(8)
                ->timeout((int) config('services.hermes_safety.timeout', 25))
                ->post(rtrim($this->chatBaseUrl(), '/').'/chat/completions', $this->chatPayload([
                    [
                        'role' => 'system',
                        'content' => 'Anda adalah AI Monitoring & Security assistant untuk JojoApp. Analisa error/failed job secara read-only. Balas JSON valid dengan key: mode, summary, likely_cause, impact, safe_actions, review_actions, risky_actions, admin_note. Jangan beri instruksi destruktif, jangan meminta secret, dan jangan menyarankan perintah yang menghapus data.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    ],
                ]))
                ->throw()
                ->json();

            $content = (string) data_get($response, 'choices.0.message.content', '');
            $decoded = json_decode($content, true);
            $analysis = is_array($decoded) ? $decoded : [
                'mode' => $this->providerLabel().' AI Monitoring & Security',
                'summary' => $content ?: $this->providerLabel().' tidak mengembalikan ringkasan.',
                'likely_cause' => '-',
                'impact' => '-',
                'safe_actions' => [],
                'review_actions' => [],
                'risky_actions' => [],
                'admin_note' => 'Response provider tidak berupa JSON penuh, isi mentah disimpan sebagai summary.',
            ];

            $report->forceFill([
                'status' => 'completed',
                'model' => $this->chatModel(),
                'duration_ms' => $this->durationMs($started),
                'context_summary' => array_merge($report->context_summary ?? [], [
                    'provider' => $provider,
                    'command' => $command,
                    'confidence' => data_get($analysis, 'confidence'),
                    'safe_actions_count' => count($this->actionList(data_get($analysis, 'safe_actions', []))),
                    'review_actions_count' => count($this->actionList(data_get($analysis, 'review_actions', []))),
                    'risky_actions_count' => count($this->actionList(data_get($analysis, 'risky_actions', []))),
                ]),
                'report' => $this->formatReport($analysis),
                'error_message' => null,
            ])->save();

            Log::channel('hermes')->info('hermes_safety.chat_completion_completed', [
                'provider' => $provider,
                'command' => $command,
                'duration_ms' => $report->duration_ms,
                'source' => $payload['source'] ?? null,
            ]);

            return $report->fresh();
        } catch (RequestException $exception) {
            return $this->fail($report, $started, $this->providerLabel().' Safety HTTP error: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($report, $started, $this->providerLabel().' Safety error: '.$exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function formatReport(array $response): string
    {
        $lines = [
            '# Hermes Safety Assistant',
            '',
            'Mode: '.data_get($response, 'mode', 'AI Safety Assistant'),
            '',
            '## Summary',
            (string) data_get($response, 'summary', '-'),
            '',
            '## Likely Cause',
            (string) data_get($response, 'likely_cause', '-'),
            '',
            '## Impact',
            (string) data_get($response, 'impact', '-'),
            '',
            '## Safe Actions',
            $this->formatActions(data_get($response, 'safe_actions', [])),
            '',
            '## Review Actions',
            $this->formatActions(data_get($response, 'review_actions', [])),
            '',
            '## Risky Actions',
            $this->formatActions(data_get($response, 'risky_actions', [])),
            '',
            '## Admin Note',
            (string) data_get($response, 'admin_note', '-'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param mixed $actions
     */
    private function formatActions(mixed $actions): string
    {
        $actions = $this->actionList($actions);

        if ($actions === []) {
            return '-';
        }

        return collect($actions)
            ->map(function (array $action): string {
                $command = filled($action['command'] ?? null) ? "\n  Command: `{$action['command']}`" : '';

                return '- '.($action['title'] ?? 'Action').': '.($action['description'] ?? '-').$command;
            })
            ->implode("\n");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actionList(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        return collect($actions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->values()
            ->all();
    }

    private function fail(HermesReport $report, float $started, string $message): ?HermesReport
    {
        $report->forceFill([
            'status' => 'failed',
            'duration_ms' => $this->durationMs($started),
            'error_message' => mb_substr($message, 0, 5000),
        ])->save();

        Log::channel('hermes')->warning('hermes_safety.failed', [
            'command' => $report->command,
            'error' => $message,
        ]);

        return $report->fresh();
    }

    private function durationMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function provider(): string
    {
        return strtolower((string) ($this->settings->get('hermes_provider') ?: config('services.hermes_safety.provider') ?: 'openai_compatible'));
    }

    private function isChatCompletionProvider(): bool
    {
        return in_array($this->provider(), ['kimi', 'openclaw', 'openrouter', 'openai'], true);
    }

    private function chatApiKey(): mixed
    {
        return match ($this->provider()) {
            'kimi' => $this->settings->get('kimi_api_key')
                ?: $this->settings->get('hermes_api_key')
                ?: config('services.hermes_safety.kimi_key'),
            default => $this->settings->get('hermes_api_key')
                ?: config('services.hermes_safety.key'),
        };
    }

    private function chatBaseUrl(): string
    {
        $custom = $this->settings->get('hermes_base_url') ?: config('services.hermes_safety.base_url');

        if (filled($custom)) {
            return (string) $custom;
        }

        return match ($this->provider()) {
            'kimi' => 'https://konektika.web.id/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'openai' => 'https://api.openai.com/v1',
            'openclaw' => (string) config('services.hermes_safety.openclaw_base_url', ''),
            default => '',
        };
    }

    private function chatModel(): string
    {
        $model = trim((string) ($this->settings->get('hermes_model') ?: config('services.hermes_safety.model') ?: ''));

        if ($this->provider() === 'kimi' && ($model === '' || str_starts_with($model, 'nousresearch/'))) {
            return 'moonshot-v1-8k';
        }

        if ($this->provider() === 'openclaw' && ($model === '' || str_starts_with($model, 'nousresearch/') || $model === 'openclaw/default')) {
            return 'kimi-pro';
        }

        return $model !== '' ? $model : 'openclaw/default';
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function chatPayload(array $messages): array
    {
        $payload = [
            'model' => $this->chatModel(),
            'messages' => $messages,
        ];

        if (! $this->requiresMinimalChatPayload()) {
            $payload['temperature'] = 0.1;
            $payload['max_tokens'] = 1200;
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    private function requiresMinimalChatPayload(): bool
    {
        return $this->provider() === 'kimi'
            || ($this->provider() === 'openclaw' && $this->chatModel() === 'kimi-pro');
    }

    /**
     * @return array<string, string>
     */
    private function chatHeaders(): array
    {
        if ($this->provider() !== 'openrouter') {
            return [];
        }

        return [
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'JojoApp AI Monitoring & Security',
        ];
    }

    private function providerLabel(): string
    {
        return match ($this->provider()) {
            'kimi' => 'Kimi',
            'openclaw' => 'OpenClaw',
            'openrouter' => 'OpenRouter',
            'openai' => 'OpenAI',
            default => 'Hermes',
        };
    }
}
