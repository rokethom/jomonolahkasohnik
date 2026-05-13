<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branch_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });

        $this->realignBranches();
        $this->seedStaffScopes();
        $this->syncHrdManagerPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_scopes');
    }

    private function realignBranches(): void
    {
        $updates = [
            'STB-KTA' => ['branch_code' => 'STBKT', 'name' => 'Situbondo', 'area' => 'Kota'],
            'STB-ASB' => ['branch_code' => 'ASB', 'name' => 'Situbondo', 'area' => 'Asembagus'],
            'STB-BSK' => ['branch_code' => 'BSK', 'name' => 'Situbondo', 'area' => 'Besuki'],
            'PBL-PAI' => ['branch_code' => 'PTN', 'name' => 'Situbondo', 'area' => 'Paiton'],
            'PBL-KRA' => ['branch_code' => 'KRK', 'name' => 'Situbondo', 'area' => 'Kraksaan'],
            'BWS-KTA' => ['branch_code' => 'BWSKT', 'name' => 'Bondowoso', 'area' => 'Bondowoso Kota'],
            'BWG-ROG' => ['branch_code' => 'RGJ', 'name' => 'Banyuwangi', 'area' => 'Rogojampi'],
            'BWG-SRO' => ['branch_code' => 'SRN', 'name' => 'Banyuwangi', 'area' => 'Srono'],
            'BWG-MUN' => ['branch_code' => 'MCR', 'name' => 'Banyuwangi', 'area' => 'Muncar'],
            'BWG-GEN' => ['branch_code' => 'GTG', 'name' => 'Banyuwangi', 'area' => 'Genteng'],
        ];

        foreach ($updates as $oldCode => $values) {
            DB::table('branches')
                ->where('branch_code', $oldCode)
                ->update([...$values, 'updated_at' => now()]);
        }
    }

    private function seedStaffScopes(): void
    {
        $situbondoIds = DB::table('branches')
            ->where('name', 'Situbondo')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $situbondoManagerIds = DB::table('branches')
            ->where('name', 'Situbondo')
            ->whereIn('area', ['Asembagus', 'Kota', 'Besuki'])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        DB::table('users')
            ->whereIn('role', ['manager', 'spv', 'eksekutor'])
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->get(['id', 'role', 'branch_id'])
            ->each(function (object $user) use ($situbondoManagerIds): void {
                $branchIds = [(int) $user->branch_id];

                if ($user->role === 'manager' && in_array((int) $user->branch_id, $situbondoManagerIds, true)) {
                    $branchIds = $situbondoManagerIds;
                }

                $this->insertScopes((int) $user->id, $branchIds);
            });

        DB::table('users')
            ->where('role', 'hrd')
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->each(function (object $user) use ($situbondoIds): void {
                $branchIds = $user->branch_id ? [(int) $user->branch_id] : $situbondoIds;
                $this->insertScopes((int) $user->id, $branchIds);
            });
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    private function insertScopes(int $userId, array $branchIds): void
    {
        foreach (array_values(array_unique($branchIds)) as $branchId) {
            DB::table('user_branch_scopes')->updateOrInsert(
                ['user_id' => $userId, 'branch_id' => $branchId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function syncHrdManagerPermissions(): void
    {
        $permissionNames = [
            'create_user',
            'suspend_driver',
            'view_report',
            'monitor_live_order',
            'monitor_live_chat',
            'internal_chat',
            'edit_tarif',
            'manage_system_settings',
            'manage_manual_order',
        ];

        $roleId = DB::table('roles')->where('name', 'hrd')->value('id');
        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
};
