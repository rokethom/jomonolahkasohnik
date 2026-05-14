<?php

namespace App\Services;

use App\Models\AiLocationSuggestion;
use App\Models\LocationPoi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AiLocationLearningService
{
    public function __construct(private readonly LocationPoiService $pois)
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

        foreach ($this->extractLocations($rawText) as $row) {
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
