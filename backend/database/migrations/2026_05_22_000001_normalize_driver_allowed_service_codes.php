<?php

use App\Support\ServiceTypeNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drivers') || ! Schema::hasColumn('drivers', 'allowed_service_types')) {
            return;
        }

        DB::table('drivers')
            ->whereNotNull('allowed_service_types')
            ->orderBy('id')
            ->select(['id', 'allowed_service_types'])
            ->chunk(200, function ($drivers): void {
                foreach ($drivers as $driver) {
                    $raw = json_decode((string) $driver->allowed_service_types, true);
                    if (! is_array($raw)) {
                        continue;
                    }

                    $normalized = ServiceTypeNormalizer::codes($raw);
                    DB::table('drivers')
                        ->where('id', $driver->id)
                        ->update(['allowed_service_types' => $normalized === [] ? null : json_encode($normalized)]);
                }
            });
    }

    public function down(): void
    {
        // Data normalization is intentionally not reversed.
    }
};
