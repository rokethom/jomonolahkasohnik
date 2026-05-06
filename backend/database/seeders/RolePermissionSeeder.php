<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'create_user',
        'suspend_driver',
        'unsuspend_driver',
        'view_report',
        'export_report',
        'edit_tarif',
        'monitor_live_order',
        'monitor_live_chat',
        'approve_cancel_order',
        'reject_cancel_order',
        'manage_cms',
    ];

    private const ROLE_PERMISSIONS = [
        'admin' => self::PERMISSIONS,
        'gm' => self::PERMISSIONS,
        'web_admin' => [
            'manage_cms',
        ],
        'cms_editor' => [
            'manage_cms',
        ],
        'hrd' => [
            'create_user',
            'suspend_driver',
            'view_report',
            'monitor_live_order',
            'monitor_live_chat',
        ],
        'manager' => [
            'create_user',
            'suspend_driver',
            'view_report',
            'monitor_live_order',
            'monitor_live_chat',
            'export_report',
            'edit_tarif',
        ],
        'spv' => [
            'suspend_driver',
            'unsuspend_driver',
            'monitor_live_order',
            'approve_cancel_order',
            'reject_cancel_order',
            'monitor_live_chat',
            'edit_tarif',
        ],
        'operator' => [
            'monitor_live_order',
            'approve_cancel_order',
            'reject_cancel_order',
            'monitor_live_chat',
            'edit_tarif',
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->mapWithKeys(fn (string $name): array => [$name => Permission::query()->firstOrCreate(['name' => $name])]);

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::query()->firstOrCreate(['name' => $roleName]);
            $role->permissions()->syncWithoutDetaching($permissions->only($permissionNames)->pluck('id')->all());
        }

        User::query()->get()->each(function (User $user): void {
            $roleName = $user->role?->value ?? (string) $user->role;
            $role = Role::query()->firstOrCreate(['name' => $roleName]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }
}
