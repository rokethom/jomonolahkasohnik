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
        'manage_ai_data',
        'monitor_live_order',
        'monitor_live_chat',
        'assign_driver',
        'approve_cancel_order',
        'reject_cancel_order',
        'approve_oper_handle',
        'reject_oper_handle',
        'manual_order',
        'internal_chat',
        'manage_system_settings',
        'manage_manual_order',
        'manage_cms',
    ];

    private const ROLE_PERMISSIONS = [
        'admin' => self::PERMISSIONS,
        'gm' => self::PERMISSIONS,
        'web_admin' => [
            'manage_cms',
            'manage_ai_data',
        ],
        'cms_editor' => [
            'manage_cms',
            'manage_ai_data',
        ],
        'hrd' => [
            'create_user',
            'suspend_driver',
            'view_report',
            'monitor_live_order',
            'monitor_live_chat',
            'internal_chat',
            'edit_tarif',
            'manage_ai_data',
            'manage_system_settings',
            'manage_manual_order',
        ],
        'manager' => [
            'create_user',
            'suspend_driver',
            'view_report',
            'monitor_live_order',
            'monitor_live_chat',
            'internal_chat',
            'export_report',
            'edit_tarif',
            'manage_ai_data',
            'manage_system_settings',
            'manage_manual_order',
        ],
        'spv' => [
            'suspend_driver',
            'unsuspend_driver',
            'monitor_live_order',
            'approve_cancel_order',
            'reject_cancel_order',
            'approve_oper_handle',
            'reject_oper_handle',
            'monitor_live_chat',
            'internal_chat',
            'edit_tarif',
            'manage_ai_data',
            'manage_system_settings',
            'manage_manual_order',
        ],
        'operator' => [
            'monitor_live_order',
            'approve_cancel_order',
            'reject_cancel_order',
            'approve_oper_handle',
            'reject_oper_handle',
            'monitor_live_chat',
            'assign_driver',
            'manual_order',
            'internal_chat',
            'edit_tarif',
            'manage_ai_data',
        ],
        'eksekutor' => [
            'monitor_live_order',
            'assign_driver',
            'approve_cancel_order',
            'reject_cancel_order',
            'approve_oper_handle',
            'reject_oper_handle',
            'monitor_live_chat',
            'manual_order',
            'internal_chat',
            'manage_ai_data',
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
