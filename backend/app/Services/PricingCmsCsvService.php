<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Filament\Resources\PriceSettingResource;
use App\Filament\Resources\RingPricingRuleResource;
use App\Filament\Resources\ZonePricingRuleResource;
use App\Models\Branch;
use App\Models\GeofenceArea;
use App\Models\PriceSetting;
use App\Models\PricingKeywordRule;
use App\Models\RingPricingRule;
use App\Models\ZonePricingRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PricingCmsCsvService
{
    private const DELIMITER = ';';

    private const HEADERS = [
        'ring' => [
            'id',
            'name',
            'branch_id',
            'branch_code',
            'branch_name',
            'service_type',
            'ring',
            'price',
            'area_mode',
            'pickup_area',
            'destination_area',
            'pickup_aliases',
            'destination_aliases',
            'polygon_match_point',
            'polygon_coordinates',
            'is_bidirectional',
            'is_active',
            'source',
        ],
        'price' => [
            'id',
            'name',
            'branch_id',
            'branch_code',
            'branch_name',
            'min_km',
            'max_km',
            'is_formula',
            'price',
            'per_km_rate',
            'subtract_value',
            'is_active',
        ],
        'keyword' => [
            'id',
            'name',
            'keywords',
            'amount',
            'service_scopes',
            'is_active',
            'priority',
            'description',
        ],
        'zone' => [
            'id',
            'name',
            'branch_id',
            'branch_code',
            'branch_name',
            'geofence_area_id',
            'geofence_area_name',
            'service_type',
            'match_point',
            'price_mode',
            'amount',
            'percent',
            'min_km',
            'max_km',
            'is_active',
            'priority',
            'notes',
        ],
    ];

    public function downloadCsv(string $type, bool $template = false): StreamedResponse
    {
        $type = $this->normalizeType($type);
        $filename = ($template ? 'template-' : '').'pricing-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($type, $template): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::HEADERS[$type], self::DELIMITER);

            if (! $template) {
                $this->queryForType($type)->chunk(200, function ($records) use ($handle, $type): void {
                    foreach ($records as $record) {
                        fputcsv($handle, $this->rowForRecord($type, $record), self::DELIMITER);
                    }
                });
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function importCsv(string $type, string $path): array
    {
        $type = $this->normalizeType($type);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File import tidak bisa dibaca.']];
        }

        try {
            [$headers, $delimiter] = $this->readHeaders($handle, $type);
        } catch (\Throwable $exception) {
            fclose($handle);

            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()]];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $data = $this->combineRow($headers, $row);

            try {
                $wasCreated = DB::transaction(fn (): bool => $this->upsertRow($type, $data));
                $wasCreated ? $created++ : $updated++;
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = "Baris {$rowNumber}: ".$exception->getMessage();
            }
        }

        fclose($handle);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 12),
        ];
    }

    private function normalizeType(string $type): string
    {
        if (! array_key_exists($type, self::HEADERS)) {
            throw new \InvalidArgumentException('Tipe pricing CSV tidak dikenal.');
        }

        return $type;
    }

    private function queryForType(string $type): Builder
    {
        return match ($type) {
            'ring' => RingPricingRule::query()
                ->with('branch')
                ->when(! $this->actorCanManageGlobalPricing(), fn (Builder $query) => $query->whereIn('branch_id', $this->scopedBranchIds()))
                ->orderBy('updated_at', 'desc'),
            'price' => PriceSetting::query()
                ->with('branch')
                ->when(! $this->actorCanManageGlobalPricing(), fn (Builder $query) => $query->whereIn('branch_id', $this->scopedBranchIds()))
                ->orderBy('min_km')
                ->orderBy('branch_id'),
            'keyword' => PricingKeywordRule::query()->orderByDesc('priority')->orderBy('name'),
            'zone' => ZonePricingRule::query()
                ->with(['branch', 'geofenceArea'])
                ->when(! $this->actorCanManageGlobalPricing(), fn (Builder $query) => $query->whereIn('branch_id', $this->scopedBranchIds()))
                ->orderByDesc('priority')
                ->orderBy('name'),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function rowForRecord(string $type, Model $record): array
    {
        return match ($type) {
            'ring' => [
                $record->id,
                $record->name,
                $record->branch_id,
                $record->branch?->branch_code,
                $record->branch?->display_name,
                $record->service_type,
                $record->ring,
                $record->price,
                $record->area_mode,
                $record->pickup_area,
                $record->destination_area,
                $this->encodeList($record->pickup_aliases),
                $this->encodeList($record->destination_aliases),
                $record->polygon_match_point,
                $this->encodeJson($record->polygon_coordinates),
                $this->yesNo($record->is_bidirectional),
                $this->yesNo($record->is_active),
                $record->source,
            ],
            'price' => [
                $record->id,
                $record->name,
                $record->branch_id,
                $record->branch?->branch_code,
                $record->branch?->display_name,
                $record->min_km,
                $record->max_km,
                $this->yesNo($record->is_formula),
                $record->price,
                $record->per_km_rate,
                $record->subtract_value,
                $this->yesNo($record->is_active),
            ],
            'keyword' => [
                $record->id,
                $record->name,
                $record->keywords,
                $record->amount,
                $this->encodeList($record->service_scopes),
                $this->yesNo($record->is_active),
                $record->priority,
                $record->description,
            ],
            'zone' => [
                $record->id,
                $record->name,
                $record->branch_id,
                $record->branch?->branch_code,
                $record->branch?->display_name,
                $record->geofence_area_id,
                $record->geofenceArea?->name,
                $record->service_type,
                $record->match_point,
                $record->price_mode,
                $record->amount,
                $record->percent,
                $record->min_km,
                $record->max_km,
                $this->yesNo($record->is_active),
                $record->priority,
                $record->notes,
            ],
        };
    }

    /**
     * @param resource $handle
     * @return array{0: array<int, string>, 1: string}
     */
    private function readHeaders($handle, string $type): array
    {
        $line = fgets($handle);

        if ($line === false) {
            throw new \RuntimeException('Header CSV tidak ditemukan.');
        }

        $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
        $headers = str_getcsv($line, $delimiter);
        $headers = array_map(fn (string $header): string => trim(str_replace("\xEF\xBB\xBF", '', $header)), $headers);
        $missing = array_diff($this->requiredHeaders($type), $headers);

        if ($missing !== []) {
            throw new \RuntimeException('Header wajib belum lengkap: '.implode(', ', $missing).'. Gunakan tombol Download Template CSV.');
        }

        return [$headers, $delimiter];
    }

    /**
     * @return array<int, string>
     */
    private function requiredHeaders(string $type): array
    {
        return match ($type) {
            'ring' => ['name', 'ring', 'price'],
            'price' => ['name', 'min_km', 'is_formula', 'is_active'],
            'keyword' => ['name', 'keywords', 'amount'],
            'zone' => ['name', 'geofence_area_id', 'match_point', 'price_mode'],
        };
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string|null> $row
     * @return array<string, string>
     */
    private function combineRow(array $headers, array $row): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $data;
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn ($value): bool => blank($value));
    }

    /**
     * @param array<string, string> $data
     */
    private function upsertRow(string $type, array $data): bool
    {
        $payload = match ($type) {
            'ring' => $this->ringPayload($data),
            'price' => $this->pricePayload($data),
            'keyword' => $this->keywordPayload($data),
            'zone' => $this->zonePayload($data),
        };

        $model = $this->modelForType($type);
        $record = filled($data['id'] ?? null) ? $model::query()->whereKey((int) $data['id'])->first() : null;

        if ($record && ! $this->recordInScope($type, $record)) {
            throw new \RuntimeException('Record tidak berada dalam scope cabang akun ini.');
        }

        if ($record) {
            if ($type === 'ring') {
                unset($payload['created_by']);
            }

            $record->forceFill($payload)->save();

            return false;
        }

        $model::query()->create($payload);

        return true;
    }

    /**
     * @return class-string<Model>
     */
    private function modelForType(string $type): string
    {
        return match ($type) {
            'ring' => RingPricingRule::class,
            'price' => PriceSetting::class,
            'keyword' => PricingKeywordRule::class,
            'zone' => ZonePricingRule::class,
        };
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function ringPayload(array $data): array
    {
        $payload = RingPricingRuleResource::normalizeScopedData([
            'branch_id' => $this->resolveBranchId($data),
            'service_type' => $this->nullableString($data['service_type'] ?? null),
            'name' => $this->requiredString($data, 'name'),
            'area_mode' => $this->nullableString($data['area_mode'] ?? null) ?: 'text',
            'pickup_area' => $this->nullableString($data['pickup_area'] ?? null),
            'destination_area' => $this->nullableString($data['destination_area'] ?? null),
            'pickup_aliases' => $this->parseList($data['pickup_aliases'] ?? ''),
            'destination_aliases' => $this->parseList($data['destination_aliases'] ?? ''),
            'polygon_coordinates' => $this->nullableString($data['polygon_coordinates'] ?? null),
            'polygon_match_point' => $this->nullableString($data['polygon_match_point'] ?? null) ?: 'destination_then_pickup',
            'ring' => $this->requiredString($data, 'ring'),
            'price' => $this->moneyInt($data['price'] ?? null),
            'is_bidirectional' => $this->booleanValue($data['is_bidirectional'] ?? 'yes'),
            'is_active' => $this->booleanValue($data['is_active'] ?? 'yes'),
            'source' => $this->nullableString($data['source'] ?? null) ?: 'manual',
        ]);

        $payload['updated_by'] = Auth::id();
        $payload['created_by'] = Auth::id();

        return $payload;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function pricePayload(array $data): array
    {
        $branchId = $this->resolveBranchId($data);
        if (! $this->actorCanManageGlobalPricing()) {
            $branchId = $this->scopedBranchIds()[0] ?? null;
        }

        $isFormula = $this->booleanValue($data['is_formula'] ?? 'no');
        if (! $isFormula && blank($data['price'] ?? null)) {
            throw new \RuntimeException('price wajib diisi saat is_formula = no.');
        }

        if ($isFormula && blank($data['per_km_rate'] ?? null)) {
            throw new \RuntimeException('per_km_rate wajib diisi saat is_formula = yes.');
        }

        return PriceSettingResource::normalizePricingData([
            'name' => $this->requiredString($data, 'name'),
            'branch_id' => $branchId,
            'min_km' => $this->decimalValue($data['min_km'] ?? null),
            'max_km' => $this->nullableDecimal($data['max_km'] ?? null),
            'is_formula' => $isFormula,
            'price' => $this->moneyInt($data['price'] ?? null),
            'per_km_rate' => $this->moneyInt($data['per_km_rate'] ?? null),
            'subtract_value' => $this->moneyInt($data['subtract_value'] ?? null),
            'is_active' => $this->booleanValue($data['is_active'] ?? 'yes'),
        ]);
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function keywordPayload(array $data): array
    {
        return [
            'name' => $this->requiredString($data, 'name'),
            'keywords' => $this->requiredString($data, 'keywords'),
            'amount' => $this->moneyInt($data['amount'] ?? null),
            'service_scopes' => $this->parseList($data['service_scopes'] ?? ''),
            'is_active' => $this->booleanValue($data['is_active'] ?? 'yes'),
            'priority' => (int) ($data['priority'] ?? 0),
            'description' => $this->nullableString($data['description'] ?? null),
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function zonePayload(array $data): array
    {
        $branchId = $this->resolveBranchId($data);
        $geofenceId = $this->resolveGeofenceId($data, $branchId);
        $geofence = GeofenceArea::query()->find($geofenceId);
        $priceMode = $this->nullableString($data['price_mode'] ?? null) ?: 'fixed';

        if (in_array($priceMode, ['fixed', 'extra'], true) && blank($data['amount'] ?? null)) {
            throw new \RuntimeException('amount wajib diisi untuk price_mode '.$priceMode.'.');
        }

        if ($priceMode === 'percent' && blank($data['percent'] ?? null)) {
            throw new \RuntimeException('percent wajib diisi untuk price_mode percent.');
        }

        if ($geofence && $branchId === null) {
            $branchId = $geofence->branch_id;
        }

        if ($geofence && $branchId !== null && (int) $geofence->branch_id !== (int) $branchId) {
            throw new \RuntimeException('Geofence tidak berada di cabang yang sama dengan rule.');
        }

        $payload = ZonePricingRuleResource::normalizeScopedData([
            'name' => $this->requiredString($data, 'name'),
            'branch_id' => $branchId,
            'geofence_area_id' => $geofenceId,
            'service_type' => $this->nullableString($data['service_type'] ?? null),
            'match_point' => $this->nullableString($data['match_point'] ?? null) ?: 'destination',
            'price_mode' => $priceMode,
            'amount' => $this->moneyInt($data['amount'] ?? null),
            'percent' => $this->nullableDecimal($data['percent'] ?? null),
            'min_km' => $this->nullableDecimal($data['min_km'] ?? null),
            'max_km' => $this->nullableDecimal($data['max_km'] ?? null),
            'is_active' => $this->booleanValue($data['is_active'] ?? 'yes'),
            'priority' => (int) ($data['priority'] ?? 0),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        if (! $this->actorCanManageGlobalPricing() && ! in_array((int) $payload['branch_id'], $this->scopedBranchIds(), true)) {
            throw new \RuntimeException('Rule zone wajib berada di cabang akun ini.');
        }

        return $payload;
    }

    /**
     * @param array<string, string> $data
     */
    private function resolveBranchId(array $data): ?int
    {
        if (filled($data['branch_id'] ?? null)) {
            $branchId = (int) $data['branch_id'];
            $branch = Branch::query()->whereKey($branchId)->first();

            if (! $branch) {
                throw new \RuntimeException('branch_id tidak ditemukan.');
            }

            return $branchId;
        }

        $branchText = trim((string) ($data['branch_code'] ?? '')) ?: trim((string) ($data['branch_name'] ?? ''));
        if ($branchText === '') {
            return null;
        }

        $branch = Branch::query()
            ->where('branch_code', $branchText)
            ->orWhere('name', $branchText)
            ->orWhere('area', $branchText)
            ->orWhereRaw("CONCAT(branch_code, ' - ', name, ' - ', area) = ?", [$branchText])
            ->first();

        if (! $branch) {
            throw new \RuntimeException('Branch tidak ditemukan: '.$branchText);
        }

        return $branch->id;
    }

    /**
     * @param array<string, string> $data
     */
    private function resolveGeofenceId(array $data, ?int $branchId): int
    {
        if (filled($data['geofence_area_id'] ?? null)) {
            $geofenceId = (int) $data['geofence_area_id'];
            if (! GeofenceArea::query()->whereKey($geofenceId)->exists()) {
                throw new \RuntimeException('geofence_area_id tidak ditemukan.');
            }

            return $geofenceId;
        }

        $name = trim((string) ($data['geofence_area_name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('geofence_area_id atau geofence_area_name wajib diisi.');
        }

        $geofence = GeofenceArea::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('name', $name)
            ->first();

        if (! $geofence) {
            throw new \RuntimeException('Geofence tidak ditemukan: '.$name);
        }

        return $geofence->id;
    }

    private function recordInScope(string $type, Model $record): bool
    {
        if ($this->actorCanManageGlobalPricing() || $type === 'keyword') {
            return true;
        }

        if ($type === 'price') {
            return $record->branch_id !== null && in_array((int) $record->branch_id, $this->scopedBranchIds(), true);
        }

        return $record->branch_id !== null && in_array((int) $record->branch_id, $this->scopedBranchIds(), true);
    }

    private function actorCanManageGlobalPricing(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager], true);
    }

    /**
     * @return array<int, int>
     */
    private function scopedBranchIds(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $user->loadMissing('branchScopes:id');

        $branchIds = $user->branchScopes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($branchIds === [] && $user->branch_id !== null) {
            $branchIds[] = (int) $user->branch_id;
        }

        return array_values(array_unique($branchIds));
    }

    /**
     * @param array<int, mixed>|null $value
     */
    private function encodeList(?array $value): string
    {
        return collect($value ?? [])->map(fn ($item): string => (string) $item)->filter()->implode('|');
    }

    private function encodeJson(mixed $value): string
    {
        return blank($value) ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function yesNo(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @return array<int, string>
     */
    private function parseList(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return collect($decoded)->map(fn ($item): string => trim((string) $item))->filter()->values()->all();
        }

        return collect(explode('|', str_replace(',', '|', $value)))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string, string> $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new \RuntimeException($key.' wajib diisi.');
        }

        return $value;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function booleanValue(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'y', 'true', 'iya', 'ya', 'aktif', 'active', 'on'], true);
    }

    private function moneyInt(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        return (int) preg_replace('/[^\d-]/', '', $value);
    }

    private function decimalValue(?string $value): float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || ! is_numeric($value)) {
            throw new \RuntimeException('Nilai angka tidak valid.');
        }

        return (float) $value;
    }

    private function nullableDecimal(?string $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new \RuntimeException('Nilai angka tidak valid: '.$value);
        }

        return (float) $value;
    }
}
