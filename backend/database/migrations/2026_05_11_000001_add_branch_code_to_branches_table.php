<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'branch_code')) {
                $table->string('branch_code', 20)->nullable()->after('id')->unique();
            }
        });

        DB::table('branches')
            ->orderBy('id')
            ->get()
            ->each(function (object $branch): void {
                $name = $this->normalizedName((string) $branch->name, (string) $branch->area);
                $area = $this->normalizedArea((string) $branch->name, (string) $branch->area);
                $code = $this->uniqueBranchCode($this->branchCode($name, $area), (int) $branch->id);

                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update([
                        'branch_code' => $code,
                        'name' => $name,
                        'area' => $area,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (Schema::hasColumn('branches', 'branch_code')) {
                $table->dropUnique(['branch_code']);
                $table->dropColumn('branch_code');
            }
        });
    }

    private function normalizedName(string $name, string $area): string
    {
        $nameKey = Str::of($name)->lower()->squish()->toString();
        $areaKey = Str::of($area)->lower()->squish()->toString();

        if ($nameKey === 'cabang a' || str_contains($areaKey, 'asembagus')) {
            return 'Situbondo';
        }

        if ($nameKey === 'cabang b' || str_contains($areaKey, 'jember')) {
            return 'Jember';
        }

        return trim($name) !== '' ? trim($name) : 'Cabang';
    }

    private function normalizedArea(string $name, string $area): string
    {
        $nameKey = Str::of($name)->lower()->squish()->toString();
        $area = trim($area);

        if ($nameKey === 'cabang a') {
            return 'Asembagus';
        }

        if ($nameKey === 'cabang b') {
            return 'Kota';
        }

        $parts = preg_split('/\s+/', $area, 2) ?: [];
        $prefix = strtoupper((string) ($parts[0] ?? ''));

        if (preg_match('/^[A-Z]{2,4}$/', $prefix) === 1 && isset($parts[1])) {
            return trim($parts[1]);
        }

        return $area !== '' ? $area : 'Kota';
    }

    private function branchCode(string $name, string $area): string
    {
        return $this->regencyCode($name).'-'.$this->areaCode($area);
    }

    private function regencyCode(string $name): string
    {
        $normalized = Str::of($name)->lower()->squish()->toString();

        return match (true) {
            str_contains($normalized, 'situbondo') => 'STB',
            str_contains($normalized, 'bondowoso') => 'BWS',
            str_contains($normalized, 'probolinggo') => 'PBL',
            str_contains($normalized, 'banyuwangi') => 'BWG',
            str_contains($normalized, 'jember') => 'JBR',
            default => $this->lettersCode($name, 'BRN'),
        };
    }

    private function areaCode(string $area): string
    {
        $normalized = Str::of($area)->lower()->replace(['-', '_', '/', ','], ' ')->squish()->toString();

        if (str_contains($normalized, 'kota')) {
            return 'KTA';
        }

        $firstToken = strtoupper(Str::of($area)->replace(['-', '_', '/', ','], ' ')->squish()->before(' ')->toString());
        if (preg_match('/^[A-Z]{2,4}$/', $firstToken) === 1) {
            return $firstToken;
        }

        return $this->lettersCode($area, 'ARE');
    }

    private function lettersCode(string $value, string $fallback): string
    {
        $letters = preg_replace('/[^a-z0-9]/i', '', $value) ?: $fallback;

        return strtoupper(substr($letters, 0, 3));
    }

    private function uniqueBranchCode(string $code, int $branchId): string
    {
        $candidate = $code;
        $counter = 2;

        while (DB::table('branches')->where('branch_code', $candidate)->where('id', '!=', $branchId)->exists()) {
            $candidate = $code.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }
};
