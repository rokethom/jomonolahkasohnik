<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_parsers', function (Blueprint $table): void {
            if (! Schema::hasColumn('keyword_parsers', 'form_schema')) {
                $table->json('form_schema')->nullable()->after('response_template');
            }
        });
    }

    public function down(): void
    {
        Schema::table('keyword_parsers', function (Blueprint $table): void {
            if (Schema::hasColumn('keyword_parsers', 'form_schema')) {
                $table->dropColumn('form_schema');
            }
        });
    }
};
