<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oper_handle_requests', function (Blueprint $table): void {
            $table->foreignId('decided_by')->nullable()->after('spv_approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('spv_approved_at');
            $table->string('decision_note', 500)->nullable()->after('decided_at');
        });

        foreach (['approve_oper_handle', 'reject_oper_handle'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['approve_oper_handle', 'reject_oper_handle'])
            ->pluck('id', 'name');
        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'gm', 'spv', 'operator', 'eksekutor'])
            ->pluck('id', 'name');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['approve_oper_handle', 'reject_oper_handle'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('oper_handle_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decided_at', 'decision_note']);
        });
    }
};
