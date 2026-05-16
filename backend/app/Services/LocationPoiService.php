<?php

namespace App\Services;

use App\Models\LocationPoi;
use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LocationPoiService
{
    public function resolve(string $text, ?int $branchId = null): ?LocationPoi
    {
        if (! Schema::hasTable('location_pois')) {
            return null;
        }

        $needle = $this->normalize($text);
        if (mb_strlen($needle) < 3) {
            return null;
        }

        $branchIds = $this->branchScopeIds($branchId);
        $cacheKey = 'location-poi:v2:'.($branchIds === [] ? 'global' : implode('-', $branchIds)).':'.sha1($needle);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($needle, $branchIds): ?LocationPoi {
            $matches = LocationPoi::query()
                ->with(['branch', 'area'])
                ->active()
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->when($branchIds !== [], fn ($query) => $query->where(fn ($query) => $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id')))
                ->get()
                ->map(fn (LocationPoi $poi): array => [
                    'poi' => $poi,
                    'score' => $this->score($needle, $poi),
                ])
                ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
                ->sortByDesc('score')
                ->values();

            return $matches->first()['poi'] ?? null;
        });
    }

    public function geocodeResult(LocationPoi $poi): array
    {
        $poi->forceFill([
            'hit_count' => $poi->hit_count + 1,
            'last_used_at' => now(),
        ])->save();

        return [
            'lat' => (float) $poi->latitude,
            'lng' => (float) $poi->longitude,
            'formatted_address' => trim(implode(', ', array_filter([
                $poi->name,
                $poi->area?->name,
                $poi->branch?->display_name,
            ]))),
            'provider' => 'master_location_poi',
            'confidence' => $poi->confidence,
            'location_poi_id' => $poi->id,
            'location_poi_name' => $poi->name,
            'query' => $poi->name,
        ];
    }

    public function aliasesFor(string $name): array
    {
        $name = trim($name);
        $normalized = $this->normalize($name);
        $words = collect(preg_split('/\s+/u', $normalized) ?: [])->filter()->values();
        $initials = $words
            ->filter(fn (string $word): bool => mb_strlen($word) >= 3)
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');

        $aliases = [
            $name,
            $normalized,
            $this->withoutAddressNoise($normalized),
        ];

        if ($initials !== '' && mb_strlen($initials) >= 2) {
            $aliases[] = $initials;
        }

        if (preg_match('/\bsmpn?\s*(\d+)/u', $normalized, $match) === 1) {
            $aliases[] = 'smp negeri '.$match[1];
            $aliases[] = 'smpn '.$match[1];
            $aliases[] = 'smp '.$match[1];
        }

        return collect($aliases)
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 2)
            ->unique(fn (string $value): string => $this->normalize($value))
            ->values()
            ->all();
    }

    public function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->replaceMatches('/\b(?:ke|dari|di|dekat|dkt|depan|belakang|samping|sebelah|arah|menuju|sekitar|area|daerah)\b/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    private function score(string $needle, LocationPoi $poi): int
    {
        if ($this->isPricingCoveragePoi($poi) && ! $this->isRingQuery($needle)) {
            return 0;
        }

        $terms = collect([$poi->name, ...($poi->aliases ?? [])])
            ->map(fn (mixed $value): string => $this->normalize((string) $value))
            ->filter()
            ->unique()
            ->values();

        $best = 0;
        foreach ($terms as $term) {
            if ($term === $needle) {
                $best = max($best, 100000);
                continue;
            }

            if (! $this->isUsableLooseTerm($term)) {
                continue;
            }

            if (Str::contains($needle, $term) || Str::contains($term, $needle)) {
                $best = max($best, 50000 + mb_strlen($term));
                continue;
            }

            $distance = levenshtein($needle, $term);
            if ($distance <= max(1, (int) floor(mb_strlen($term) / 5))) {
                $best = max($best, 20000 - ($distance * 100));
            }
        }

        return $best === 0 ? 0 : $best + ((int) $poi->priority * 10) + (int) $poi->confidence;
    }

    private function isPricingCoveragePoi(LocationPoi $poi): bool
    {
        return str_starts_with($this->normalize((string) $poi->name), 'ring ')
            || in_array(strtolower((string) $poi->source), ['geojson', 'generated'], true)
                && preg_match('/^ring\s*\d+/u', $this->normalize((string) $poi->name)) === 1;
    }

    private function isRingQuery(string $needle): bool
    {
        return preg_match('/\bring\s*\d*\b/u', $needle) === 1;
    }

    private function isUsableLooseTerm(string $term): bool
    {
        return mb_strlen($term) >= 3;
    }

    private function withoutAddressNoise(string $value): string
    {
        return Str::of($value)
            ->replaceMatches('/\b(?:jalan|jl|jln|perumahan|perum|blok|gang|gg)\b/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    /**
     * @return array<int, int>
     */
    private function branchScopeIds(?int $branchId): array
    {
        if (! $branchId) {
            return [];
        }

        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            return [(int) $branchId];
        }

        $rootId = $branch->parent_branch_id ?: $branch->id;
        $childIds = Branch::query()
            ->where('parent_branch_id', $rootId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return collect([$branch->id, $rootId, ...$childIds])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
