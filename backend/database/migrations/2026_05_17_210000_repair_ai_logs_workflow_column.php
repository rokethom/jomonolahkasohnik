<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_logs') || ! Schema::hasColumn('ai_logs', 'workflow')) {
            return;
        }

        DB::statement("UPDATE ai_logs SET workflow = COALESCE(NULLIF(workflow, ''), COALESCE(source, 'unknown'))");
        DB::statement("ALTER TABLE ai_logs MODIFY workflow VARCHAR(100) NOT NULL DEFAULT 'unknown'");
    }

    public function down(): void
    {
        // Production repair migration. Keep workflow compatible with legacy rows.
    }
};
