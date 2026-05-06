<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'auth_failed_attempts')) {
                $table->unsignedTinyInteger('auth_failed_attempts')->default(0)->after('last_login_ip');
            }

            if (! Schema::hasColumn('drivers', 'auth_locked_until')) {
                $table->timestamp('auth_locked_until')->nullable()->after('auth_failed_attempts');
            }

            if (! Schema::hasColumn('drivers', 'auth_suspended_at')) {
                $table->timestamp('auth_suspended_at')->nullable()->after('auth_locked_until');
            }

            if (! Schema::hasColumn('drivers', 'last_login_device')) {
                $table->string('last_login_device', 120)->nullable()->after('last_login_ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            foreach (['auth_failed_attempts', 'auth_locked_until', 'auth_suspended_at', 'last_login_device'] as $column) {
                if (Schema::hasColumn('drivers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
