<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'name')) {
                $table->string('name')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('drivers', 'email')) {
                $table->string('email')->nullable()->after('name')->index();
            }

            if (! Schema::hasColumn('drivers', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email');
            }

            if (! Schema::hasColumn('drivers', 'is_suspend')) {
                $table->boolean('is_suspend')->default(false)->after('status');
            }

            if (! Schema::hasColumn('drivers', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('suspended_until');
            }

            if (! Schema::hasColumn('drivers', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
        });

        DB::table('drivers')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->whereNull('drivers.email')
            ->update([
                'drivers.name' => DB::raw('users.name'),
                'drivers.email' => DB::raw('users.email'),
            ]);
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'google_id')) {
                $table->dropUnique('drivers_google_id_unique');
            }

            if (Schema::hasColumn('drivers', 'email')) {
                $table->dropIndex('drivers_email_index');
            }

            foreach (['name', 'email', 'google_id', 'is_suspend', 'last_login_at', 'last_login_ip'] as $column) {
                if (Schema::hasColumn('drivers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
