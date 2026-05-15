<?php

namespace App\Services;

use App\Models\AiAliasMap;
use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiAliasMapService
{
    public function resolve(string $text, ?int $branchId = null): ?AiAliasMap
    {
        if (! Schema::hasTable('ai_alias_maps')) {
            return null;
        }

        $needle = $this->normalize($text);
        if (mb_strlen($needle) < 3) {
            return null;
        }

        $branchIds = $this->branchScopeIds($branchId);
        $cacheKey = 'ai-alias-map:'.($branchIds === [] ? 'global' : implode('-', $branchIds)).':'.sha1($needle);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($needle, $branchIds): ?AiAliasMap {
            /** @var Collection<int, array{map: AiAliasMap, score: int}> $matches */
            $matches = AiAliasMap::query()
                ->with(['branch', 'area', 'geojsonRegion.branch', 'geojsonRegion.area'])
                ->active()
                ->when($branchIds !== [], fn ($query) => $query->where(fn ($query) => $query->whereIn('branch_id', $branchIds)->orWhereNull('branch_id')))
                ->get()
                ->map(fn (AiAliasMap $map): array => [
                    'map' => $map,
                    'score' => $this->score($needle, $map),
                ])
                ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
                ->sortByDesc('score')
                ->values();

            return $matches->first()['map'] ?? null;
        });
    }

    public function geocodeResult(AiAliasMap $map): ?array
    {
        $region = $map->geojsonRegion;
        if (! $region || ! is_numeric($region->centroid_lat) || ! is_numeric($region->centroid_lng)) {
            return null;
        }

        $map->forceFill([
            'hit_count' => $map->hit_count + 1,
            'last_used_at' => now(),
        ])->save();

        return [
            'lat' => (float) $region->centroid_lat,
            'lng' => (float) $region->centroid_lng,
            'formatted_address' => trim(implode(', ', array_filter([
                $map->canonical_name,
                $map->area?->name ?? $region->area?->name,
                $map->branch?->display_name ?? $region->branch?->display_name,
            ]))),
            'provider' => 'ai_alias_map',
            'confidence' => $map->confidence,
            'ai_alias_map_id' => $map->id,
            'ai_alias_canonical_name' => $map->canonical_name,
            'geojson_region_id' => $region->id,
            'geojson_region_name' => $region->name,
            'geojson_area_id' => $region->area_id,
            'geojson_area_name' => $region->area?->name,
            'query' => $map->canonical_name,
        ];
    }

    public function generateFromGeojsonAndOrders(?int $branchId = null): array
    {
        if (! Schema::hasTable('ai_alias_maps') || ! Schema::hasTable('geojson_regions')) {
            return ['created' => 0, 'updated' => 0];
        }

        $created = 0;
        $updated = 0;

        GeojsonRegion::query()
            ->with(['branch', 'area'])
            ->active()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->each(function (GeojsonRegion $region) use (&$created, &$updated): void {
                $aliases = $this->defaultAliasesForRegion($region);
                $map = AiAliasMap::query()->firstOrNew([
                    'geojson_region_id' => $region->id,
                    'canonical_name' => $region->name,
                ]);

                $exists = $map->exists;
                $map->fill([
                    'branch_id' => $region->branch_id,
                    'area_id' => $region->area_id,
                    'aliases' => $this->sanitizeAliasesForRegion($this->mergeAliases($map->aliases ?? [], $aliases), $region),
                    'source' => $exists ? $map->source : 'generated',
                    'confidence' => max((int) ($map->confidence ?: 0), 85),
                    'priority' => max((int) ($map->priority ?: 0), 10),
                    'is_active' => true,
                ])->save();

                $exists ? $updated++ : $created++;
            });

        $this->learnFromOrderHistory($branchId);
        Cache::flush();

        return ['created' => $created, 'updated' => $updated];
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

    private function score(string $needle, AiAliasMap $map): int
    {
        $terms = collect([$map->canonical_name, ...($map->aliases ?? [])])
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

            if ($this->isBroadContextTerm($term, $map)) {
                continue;
            }

            if (Str::contains($needle, $term) || Str::contains($term, $needle)) {
                $best = max($best, 50000 + mb_strlen($term));
                continue;
            }

            $distance = levenshtein($needle, $term);
            $limit = max(1, (int) floor(mb_strlen($term) / 5));
            if ($distance <= $limit) {
                $best = max($best, 20000 - ($distance * 100));
                continue;
            }

            similar_text($needle, $term, $percent);
            if ($percent >= 86 && abs(mb_strlen($needle) - mb_strlen($term)) <= 2) {
                $best = max($best, 15000 + (int) $percent);
            }
        }

        if ($best === 0) {
            return 0;
        }

        return $best + ((int) $map->priority * 10) + (int) $map->confidence;
    }

    private function defaultAliasesForRegion(GeojsonRegion $region): array
    {
        $name = trim((string) $region->name);
        $area = trim((string) ($region->area?->name ?? ''));
        $branch = trim((string) ($region->branch?->name ?? ''));
        $branchArea = trim((string) ($region->branch?->area ?? ''));

        $aliases = [
            $name,
            $this->withoutAdministrativeWords($name),
            'dekat '.$name,
            'sebelah '.$name,
        ];

        if ($area !== '') {
            $aliases[] = $name.' '.$area;
            $aliases[] = $area.' '.$name;
        }

        if ($branch !== '' && $branchArea !== '') {
            $aliases[] = $branch.' '.$branchArea;
            $aliases[] = $branchArea.' '.$branch;
        }

        if ((Str::contains($this->normalize($name), 'kota') || ($branch !== '' && $this->normalize($name) === $this->normalize($branch))) && $branch !== '') {
            $aliases[] = 'kota';
            $aliases[] = $branch.' kota';
        }

        return $this->mergeAliases([], $aliases);
    }

    private function learnFromOrderHistory(?int $branchId = null): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Order::query()
            ->select(['pickup_address', 'destination_address', 'branch_id'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->limit(500)
            ->get()
            ->each(function (Order $order): void {
                foreach ([$order->pickup_address, $order->destination_address] as $address) {
                    $normalizedAddress = $this->normalize((string) $address);
                    if (mb_strlen($normalizedAddress) < 4) {
                        continue;
                    }

                    $map = $this->resolve($normalizedAddress, $order->branch_id);
                    if (! $map instanceof AiAliasMap) {
                        continue;
                    }

                    $map->forceFill([
                        'aliases' => $this->mergeAliases($map->aliases ?? [], [(string) $address]),
                        'source' => $map->source === 'manual' ? 'manual' : 'history',
                    ])->save();
                }
            });
    }

    private function withoutAdministrativeWords(string $value): string
    {
        return Str::of($value)
            ->replaceMatches('/\b(?:desa|kelurahan|kecamatan|kabupaten|kab\.|kec\.|kel\.)\b/iu', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    private function mergeAliases(array $current, array $new): array
    {
        return collect([...$current, ...$new])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 2)
            ->unique(fn (string $value): string => $this->normalize($value))
            ->take(40)
            ->values()
            ->all();
    }

    private function sanitizeAliasesForRegion(array $aliases, GeojsonRegion $region): array
    {
        $branch = trim((string) ($region->branch?->name ?? ''));
        $isCityTarget = Str::contains($this->normalize((string) $region->name), 'kota')
            || ($branch !== '' && $this->normalize((string) $region->name) === $this->normalize($branch));

        if ($isCityTarget) {
            return $aliases;
        }

        $blocked = collect([
            'kota',
            $branch !== '' ? $branch.' kota' : null,
            $branch !== '' ? 'kota '.$branch : null,
        ])
            ->filter()
            ->map(fn (string $value): string => $this->normalize($value))
            ->all();

        return collect($aliases)
            ->reject(fn (string $alias): bool => in_array($this->normalize($alias), $blocked, true))
            ->values()
            ->all();
    }

    private function isBroadContextTerm(string $term, AiAliasMap $map): bool
    {
        $branch = $map->branch ?? $map->geojsonRegion?->branch;
        $area = $map->area ?? $map->geojsonRegion?->area;

        $broadTerms = collect([
            'kota',
            $branch?->name,
            $branch?->area,
            $branch?->branch_code,
            $area?->code,
        ])
            ->map(fn (mixed $value): string => $this->normalize((string) $value))
            ->filter()
            ->unique()
            ->all();

        return in_array($term, $broadTerms, true);
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
