<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ring_pricing_suggestions', function (Blueprint $table): void {
            if (! Schema::hasColumn('ring_pricing_suggestions', 'suggestion_type')) {
                $table->string('suggestion_type', 60)->default('price_edit')->after('ring')->index();
            }

            if (! Schema::hasColumn('ring_pricing_suggestions', 'learning_source')) {
                $table->string('learning_source', 80)->nullable()->after('suggestion_type')->index();
            }

            if (! Schema::hasColumn('ring_pricing_suggestions', 'system_price')) {
                $table->unsignedInteger('system_price')->nullable()->after('previous_price');
            }

            if (! Schema::hasColumn('ring_pricing_suggestions', 'price_delta')) {
                $table->integer('price_delta')->default(0)->after('system_price');
            }

            if (! Schema::hasColumn('ring_pricing_suggestions', 'confidence')) {
                $table->unsignedTinyInteger('confidence')->default(60)->after('occurrence_count');
            }

            if (! Schema::hasColumn('ring_pricing_suggestions', 'evidence')) {
                $table->json('evidence')->nullable()->after('sample_order_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ring_pricing_suggestions', function (Blueprint $table): void {
            foreach (['evidence', 'confidence', 'price_delta', 'system_price', 'learning_source', 'suggestion_type'] as $column) {
                if (Schema::hasColumn('ring_pricing_suggestions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
