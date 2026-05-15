<?php

namespace App\Services;

use App\Models\AiLocationSuggestion;
use App\Models\LocationPoi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiLocationLearningService
{
    public function __construct(
        private readonly LocationPoiService $pois,
        private readonly SettingService $settings,
    )
    {
    }

    public function learnFromWhatsappText(string $rawText, ?int $branchId = null, ?int $areaId = null): array
    {
        if (! Schema::hasTable('ai_location_suggestions')) {
            return ['created' => 0, 'updated' => 0, 'suggestions' => []];
        }

        $created = 0;
        $updated = 0;
        $suggestions = [];

        foreach ($this->locationRows($rawText) as $row) {
            $normalized = $this->pois->normalize($row['text']);
            if (mb_strlen($normalized) < 3 || $this->isIgnored($normalized)) {
                continue;
            }

            $suggestion = AiLocationSuggestion::query()->firstOrNew([
                'branch_id' => $branchId,
                'normalized_text' => $normalized,
                'role' => $row['role'],
            ]);

            $suggestion->fill([
                'area_id' => $areaId,
                'location_text' => $row['text'],
                'service_type' => $this->detectServiceType($rawText),
                'aliases' => $this->pois->aliasesFor($row['text']),
                'raw_text' => $rawText,
                'example_payload' => $row,
                'occurrence_count' => ($suggestion->exists ? $suggestion->occurrence_count : 0) + 1,
                'confidence' => max((int) ($suggestion->confidence ?: 0), $row['confidence']),
                'status' => $suggestion->status ?: 'pending',
            ])->save();

            $suggestion->wasRecentlyCreated ? $created++ : $updated++;
            $suggestions[] = $suggestion;
        }

        Cache::flush();

        return compact('created', 'updated', 'suggestions');
    }

    public function approve(AiLocationSuggestion $suggestion, array $overrides = []): LocationPoi
    {
        $poi = LocationPoi::query()->updateOrCreate(
            [
                'branch_id' => $overrides['branch_id'] ?? $suggestion->branch_id,
                'name' => $overrides['name'] ?? $suggestion->location_text,
            ],
            [
                'area_id' => $overrides['area_id'] ?? $suggestion->area_id,
                'name' => $overrides['name'] ?? $suggestion->location_text,
                'aliases' => $this->mergeAliases($suggestion->aliases ?? [], $overrides['aliases'] ?? []),
                'category' => $overrides['category'] ?? $suggestion->role,
                'latitude' => $overrides['latitude'] ?? $suggestion->latitude,
                'longitude' => $overrides['longitude'] ?? $suggestion->longitude,
                'source' => 'whatsapp_learning',
                'confidence' => max(70, (int) $suggestion->confidence),
                'priority' => $overrides['priority'] ?? 20,
                'is_active' => (bool) ($overrides['is_active'] ?? ($overrides['latitude'] ?? $suggestion->latitude) && ($overrides['longitude'] ?? $suggestion->longitude)),
            ],
        );

        $suggestion->forceFill([
            'location_poi_id' => $poi->id,
            'status' => 'approved',
        ])->save();

        Cache::flush();

        return $poi;
    }

    /**
     * @return array<int, array{text: string, role: string, confidence: int}>
     */
    private function extractLocations(string $rawText): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));
            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($label);
            if (preg_match('/jemput|pickup|asal/u', $key) === 1) {
                $rows[] = ['text' => $value, 'role' => 'pickup', 'confidence' => 90];
            } elseif (preg_match('/tujuan|antar|destination|penerima/u', $key) === 1) {
                $rows[] = ['text' => $value, 'role' => 'destination', 'confidence' => 90];
            } elseif (preg_match('/pembelian|lokasi\s+(?:beli|toko)|toko|store|warung|resto|pasar/u', $key) === 1) {
                $rows[] = ['text' => $value, 'role' => 'store', 'confidence' => 85];
            }
        }

        if (preg_match_all('/(?:dari|jemput)\s+(.+?)\s+(?:ke|tujuan|antar(?:kan)?\s+ke)\s+(.+?)(?:[.,\n]|$)/iu', $rawText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rows[] = ['text' => trim($match[1]), 'role' => 'pickup', 'confidence' => 70];
                $rows[] = ['text' => trim($match[2]), 'role' => 'destination', 'confidence' => 70];
            }
        }

        return collect($rows)
            ->map(fn (array $row): array => [...$row, 'text' => $this->cleanLocation($row['text'])])
            ->filter(fn (array $row): bool => mb_strlen($row['text']) >= 3)
            ->unique(fn (array $row): string => $row['role'].'|'.$this->pois->normalize($row['text']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{text: string, role: string, confidence: int}>
     */
    private function locationRows(string $rawText): array
    {
        $rows = $this->extractLocations($rawText);
        if (! $this->openRouterLearningReady()) {
            return $rows;
        }

        try {
            $aiRows = $this->extractLocationsWithOpenRouter($rawText);
            return collect([...$rows, ...$aiRows])
                ->filter(fn (array $row): bool => filled($row['text'] ?? null) && filled($row['role'] ?? null))
                ->unique(fn (array $row): string => $row['role'].'|'.$this->pois->normalize((string) $row['text']))
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('ai_location_learning.openrouter_failed', [
                'message' => $exception->getMessage(),
            ]);

            return $rows;
        }
    }

    private function openRouterLearningReady(): bool
    {
        return $this->settings->bool('ai_location_learning_openrouter_enabled', false)
            && strtolower((string) $this->settings->get('ai_provider', 'openai')) === 'openrouter'
            && filled($this->settings->get('openrouter_api_key'));
    }

    /**
     * @return array<int, array{text: string, role: string, confidence: int}>
     */
    private function extractLocationsWithOpenRouter(string $rawText): array
    {
        $model = $this->settings->bool('ai_openrouter_free_auto_enabled', false)
            ? 'openrouter/free'
            : (string) ($this->settings->get('ai_model') ?: 'openrouter/free');

        $response = Http::connectTimeout(2)
            ->timeout(6)
            ->acceptJson()
            ->withToken((string) $this->settings->get('openrouter_api_key'))
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'JOJO AI Location Learning',
            ])
            ->post(rtrim((string) ($this->settings->get('ai_base_url') ?: 'https://openrouter.ai/api/v1'), '/').'/chat/completions', [
                'model' => $model,
                'temperature' => 0.1,
                'max_tokens' => 500,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Ekstrak kandidat lokasi dari teks order WhatsApp Indonesia. Balas JSON valid: {"locations":[{"text":"...","role":"pickup|destination|store","confidence":0-100}]}. Jangan menebak nama customer atau nomor HP sebagai lokasi.',
                    ],
                    ['role' => 'user', 'content' => $rawText],
                ],
            ]);

        if (! $response->successful()) {
            return [];
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;

        return collect(data_get(is_array($decoded) ? $decoded : [], 'locations', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'text' => $this->cleanLocation((string) ($row['text'] ?? '')),
                'role' => in_array($row['role'] ?? '', ['pickup', 'destination', 'store'], true) ? (string) $row['role'] : 'destination',
                'confidence' => max(60, min(95, (int) ($row['confidence'] ?? 75))),
            ])
            ->filter(fn (array $row): bool => mb_strlen($row['text']) >= 3)
            ->values()
            ->all();
    }

    private function cleanLocation(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/^(alamat|lokasi)\s+/iu', '', $value) ?? '') ?? $value);
    }

    private function detectServiceType(string $rawText): ?string
    {
        $text = mb_strtolower($rawText);

        return match (true) {
            str_contains($text, 'ojek') || preg_match('/\boj\b/u', $text) === 1 => 'ojek',
            str_contains($text, 'kurir') => 'kurir',
            str_contains($text, 'belanja') || str_contains($text, 'belikan') => 'belanja',
            default => null,
        };
    }

    private function isIgnored(string $value): bool
    {
        return in_array($value, ['rumah saya', 'alamat saya', 'alamat customer', 'rumah', 'lokasi saya'], true);
    }

    private function mergeAliases(array $current, array $new): array
    {
        return collect([...$current, ...$new])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => $this->pois->normalize($value))
            ->take(40)
            ->values()
            ->all();
    }
}
