<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'dispatch_repost_count')) {
                $table->unsignedTinyInteger('dispatch_repost_count')->default(0)->after('expired_at');
            }

            if (! Schema::hasColumn('orders', 'last_reposted_at')) {
                $table->timestamp('last_reposted_at')->nullable()->after('dispatch_repost_count');
            }

            if (! Schema::hasColumn('orders', 'last_reposted_by')) {
                $table->foreignId('last_reposted_by')->nullable()->after('last_reposted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'last_reposted_by')) {
                $table->dropConstrainedForeignId('last_reposted_by');
            }

            if (Schema::hasColumn('orders', 'last_reposted_at')) {
                $table->dropColumn('last_reposted_at');
            }

            if (Schema::hasColumn('orders', 'dispatch_repost_count')) {
                $table->dropColumn('dispatch_repost_count');
            }
        });
    }
};
