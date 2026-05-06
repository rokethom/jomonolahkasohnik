<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('id');
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('branch_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false)->after('branch_id');
            $table->boolean('is_active')->default(true)->after('is_staff');
            $table->boolean('is_suspended')->default(false)->after('is_active');
            $table->text('suspension_reason')->nullable()->after('is_suspended');
            $table->timestamp('suspended_until')->nullable()->after('suspension_reason');

            $table->index(['role', 'is_active', 'is_suspended']);
            $table->index(['branch_id', 'role']);
        });

        DB::table('users')->orderBy('id')->each(function ($user): void {
            $role = UserRole::tryFrom($user->role) ?? UserRole::Customer;

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'username' => $user->email ? str($user->email)->before('@')->slug('_').'_'.$user->id : 'user_'.$user->id,
                    'is_staff' => $role->isStaff(),
                    'is_active' => true,
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['role', 'is_active', 'is_suspended']);
            $table->dropIndex(['branch_id', 'role']);
            $table->dropColumn([
                'username',
                'phone',
                'branch_id',
                'is_staff',
                'is_active',
                'is_suspended',
                'suspension_reason',
                'suspended_until',
            ]);
        });
    }
};
