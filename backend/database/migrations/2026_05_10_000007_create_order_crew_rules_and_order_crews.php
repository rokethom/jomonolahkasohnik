<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_crew_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('keywords')->nullable();
            $table->json('service_scopes')->nullable();
            $table->string('helper_role')->default('helper');
            $table->string('helper_label')->default('Helper');
            $table->unsignedInteger('helper_service_charge')->default(0);
            $table->boolean('requires_helper')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('order_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_crew_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->default('helper');
            $table->string('label')->default('Helper');
            $table->string('status')->default('pending');
            $table->unsignedInteger('service_charge')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'role']);
            $table->index(['status', 'role']);
        });

        DB::table('order_crew_rules')->insert([
            'name' => 'Kue tart butuh helper',
            'keywords' => 'kue tart, tart',
            'service_scopes' => json_encode(['delivery', 'belanja', 'gift_order']),
            'helper_role' => 'helper',
            'helper_label' => 'Helper kue tart',
            'helper_service_charge' => 0,
            'requires_helper' => true,
            'is_active' => true,
            'priority' => 100,
            'description' => 'Barang fragile seperti kue tart membutuhkan rider dan helper. Customer tetap melihat satu order.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_crews');
        Schema::dropIfExists('order_crew_rules');
    }
};
