<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'parent_branch_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->foreignId('parent_branch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->index(['parent_branch_id', 'branch_code']);
            });
        }

        $this->seedRegencyHierarchy();
        $this->disableManagerHrdGlobalScope();
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'parent_branch_id')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('parent_branch_id');
            });
        }
    }

    private function seedRegencyHierarchy(): void
    {
        $now = now();
        $parents = [
            'STB' => ['name' => 'Situbondo', 'latitude' => -7.70600000, 'longitude' => 114.00900000],
            'BWS' => ['name' => 'Bondowoso', 'latitude' => -7.91346000, 'longitude' => 113.82145000],
            'BWI' => ['name' => 'Banyuwangi', 'latitude' => -8.21923000, 'longitude' => 114.36923000],
        ];

        foreach ($parents as $code => $parent) {
            DB::table('branches')->updateOrInsert(
                ['branch_code' => $code],
                [
                    'parent_branch_id' => null,
                    'name' => $parent['name'],
                    'area' => null,
                    'latitude' => $parent['latitude'],
                    'longitude' => $parent['longitude'],
                    'radius_km' => 5,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $parentIds = DB::table('branches')
            ->whereIn('branch_code', array_keys($parents))
            ->pluck('id', 'branch_code');

        $children = [
            'STB' => [
                'kota' => 'STBKT',
                'situbondo kota' => 'STBKT',
                'asembagus' => 'STBASB',
                'besuki' => 'STBBSK',
                'paiton' => 'STBPTN',
                'kraksaan' => 'STBKRK',
            ],
            'BWS' => [
                'kota' => 'BWSKT',
                'bondowoso kota' => 'BWSKT',
            ],
            'BWI' => [
                'rogojampi' => 'BWIRGJ',
                'srono' => 'BWISRN',
                'muncar' => 'BWIMCR',
                'genteng' => 'BWIGTG',
            ],
        ];

        DB::table('branches')
            ->whereNotIn('branch_code', array_keys($parents))
            ->orderBy('id')
            ->get(['id', 'branch_code', 'name', 'area'])
            ->each(function (object $branch) use ($children, $parentIds, $now): void {
                $oldCode = strtoupper((string) $branch->branch_code);
                $regency = $this->regencyCode((string) $branch->name) ?: $this->regencyFromCode($oldCode);
                if (! isset($children[$regency]) || ! $parentIds->has($regency)) {
                    return;
                }

                $area = $this->normalized((string) $branch->area);
                $code = $children[$regency][$area] ?? $this->areaCodeFromCode($oldCode);
                if ($code === null) {
                    return;
                }

                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update([
                        'parent_branch_id' => (int) $parentIds[$regency],
                        'branch_code' => $this->uniqueBranchCode($code, (int) $branch->id),
                        'updated_at' => $now,
                    ]);
            });
    }

    private function disableManagerHrdGlobalScope(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'branch_global_access_roles'],
            [
                'value' => json_encode([]),
                'is_active' => true,
                'meta' => json_encode([
                    'label' => 'Role lintas branch global',
                    'note' => 'Kosong berarti HRD/Manager mengikuti parent Branch kota/kab dan area di bawahnya.',
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function regencyCode(string $name): string
    {
        $normalized = $this->normalized($name);

        return match (true) {
            str_contains($normalized, 'situbondo') => 'STB',
            str_contains($normalized, 'bondowoso') => 'BWS',
            str_contains($normalized, 'banyuwangi') => 'BWI',
            default => '',
        };
    }

    private function normalized(string $value): string
    {
        return str($value)->lower()->replace(['-', '_', '/', ','], ' ')->squish()->toString();
    }

    private function regencyFromCode(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'STB'), in_array($code, ['ASB', 'BSK', 'PTN', 'KRK'], true) => 'STB',
            str_starts_with($code, 'BWS') => 'BWS',
            str_starts_with($code, 'BWI'), in_array($code, ['RGJ', 'SRN', 'MCR', 'GTG'], true) => 'BWI',
            default => '',
        };
    }

    private function areaCodeFromCode(string $code): ?string
    {
        return match ($code) {
            'STBKT', 'STB', 'KT' => 'STBKT',
            'STBASB', 'ASB' => 'STBASB',
            'STBBSK', 'BSK' => 'STBBSK',
            'STBPTN', 'PTN' => 'STBPTN',
            'STBKRK', 'KRK' => 'STBKRK',
            'BWSKT' => 'BWSKT',
            'BWIRGJ', 'RGJ' => 'BWIRGJ',
            'BWISRN', 'SRN' => 'BWISRN',
            'BWIMCR', 'MCR' => 'BWIMCR',
            'BWIGTG', 'GTG' => 'BWIGTG',
            default => null,
        };
    }

    private function uniqueBranchCode(string $code, int $branchId): string
    {
        $candidate = $code;
        $counter = 2;

        while (DB::table('branches')->where('branch_code', $candidate)->where('id', '!=', $branchId)->exists()) {
            $suffix = (string) $counter;
            $candidate = substr($code, 0, 20 - strlen($suffix)).$suffix;
            $counter++;
        }

        return $candidate;
    }
};
