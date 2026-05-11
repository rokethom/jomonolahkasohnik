<?php

namespace App\Services;

use App\Models\HermesReport;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HermesSafetyAssistantService
{
    public function enabled(): bool
    {
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
}
