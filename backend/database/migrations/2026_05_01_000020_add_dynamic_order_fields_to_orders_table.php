<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('driver_id')->constrained('services')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            $table->string('service_code', 10)->nullable()->after('service_type')->index();
            $table->string('source', 30)->default('customer')->after('total_price')->index();
            $table->text('raw_text')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['service_code', 'source', 'raw_text']);
        });
    }
};
