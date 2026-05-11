<?php

namespace App\Services;

class RolePermissionCatalog
{
    /**
     * @return array<string, string>
     */
    public function managedRoles(): array
    {
        return [
            'hrd' => 'HRD',
            'manager' => 'Manager',
            'spv' => 'SPV',
            'operator' => 'Operator',
            'eksekutor' => 'Eksekutor',
            'web_admin' => 'Web Admin',
            'cms_editor' => 'CMS Editor',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function groups(): array
    {
        return [
            'User & Driver' => [
                'create_user' => 'Create / edit user',
                'suspend_driver' => 'Suspend driver',
                'unsuspend_driver' => 'Release suspend driver',
            ],
            'Order Operations' => [
                'monitor_live_order' => 'Monitor live order',
                'assign_driver' => 'Assign / broadcast driver',
                'manual_order' => 'Create manual order',
                'approve_cancel_order' => 'Approve cancel request',
                'reject_cancel_order' => 'Reject cancel request',
            ],
            'Pricing CMS' => [
                'edit_tarif' => 'Edit tarif, pricing, master ring, zone pricing',
            ],
            'Report' => [
                'view_report' => 'View report',
                'export_report' => 'Export report',
            ],
            'Communication' => [
                'monitor_live_chat' => 'Monitor live chat',
                'internal_chat' => 'Internal chat & notes',
            ],
            'System & CMS' => [
                'manage_system_settings' => 'Manage system settings',
                'manage_manual_order' => 'Manage manual order CMS',
                'manage_cms' => 'Manage content CMS',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return collect($this->groups())
            ->reduce(fn (array $permissions, array $group): array => [...$permissions, ...$group], []);
    }
}
