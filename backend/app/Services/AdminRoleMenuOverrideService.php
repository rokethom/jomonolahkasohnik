<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

class AdminRoleMenuOverrideService
{
    public const SETTING_KEY = 'admin_role_menu_overrides';

    /**
     * @return array<string, array<string, string>>
     */
    public function menuGroups(): array
    {
        return [
            'Overview' => [
                'dashboard' => 'Dashboard',
                'reports' => 'Reports',
            ],
            'Operations' => [
                'orders' => 'Order Operations',
                'request-orders' => 'Request Order',
                'chats' => 'Chat Monitor',
                'internal-chat' => 'Internal Chat',
                'manual-order' => 'Manual Order',
            ],
            'Management' => [
                'users' => 'Users',
                'drivers' => 'Driver Management',
            ],
            'Area & System' => [
                'locations' => 'Location Logs',
                'pricing' => 'Pricing & Policy',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function allViews(): array
    {
        return collect($this->menuGroups())
            ->flatMap(fn (array $menus): array => array_keys($menus))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<int, string>
     */
    public function allowedViewsForRole(UserRole|string $role, array $permissions): array
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;

        if (in_array($roleValue, [UserRole::Admin->value, UserRole::GM->value], true)) {
            return $this->allViews();
        }

        $views = ['dashboard'];

        if ($permissions['can_monitor_live_order'] ?? false) {
            $views[] = 'orders';
            $views[] = 'request-orders';
        }

        if ($permissions['can_assign_driver'] ?? false) {
            $views[] = 'drivers';
        }

        if ($permissions['can_manage_users'] ?? false) {
            $views[] = 'users';
        }

        if (($permissions['can_suspend_drivers'] ?? false) || ($permissions['can_unsuspend_drivers'] ?? false)) {
            $views[] = 'drivers';
        }

        if (($permissions['can_edit_order_price'] ?? false) || ($permissions['can_manage_policy'] ?? false)) {
            $views[] = 'pricing';
        }

        if ($permissions['can_view_report'] ?? false) {
            $views[] = 'reports';
        }

        if ($permissions['can_monitor_live_chat'] ?? false) {
            $views[] = 'chats';
        }

        if ($permissions['can_use_internal_chat'] ?? false) {
            $views[] = 'internal-chat';
        }

        if ($permissions['can_create_manual_order'] ?? false) {
            $views[] = 'manual-order';
        }

        if (in_array($roleValue, [
            UserRole::Manager->value,
            UserRole::SPV->value,
            UserRole::Operator->value,
            UserRole::Eksekutor->value,
        ], true)) {
            $views[] = 'locations';
        }

        $views = array_values(array_unique($views));
        $hidden = $this->hiddenViewsForRole($roleValue);

        return collect($views)
            ->reject(fn (string $view): bool => $view !== 'dashboard' && in_array($view, $hidden, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, mixed>
     */
    public function applyToPermissions(User $user, array $permissions): array
    {
        $permissions['allowed_views'] = $this->allowedViewsForRole($user->role, $permissions);

        return $permissions;
    }

    /**
     * @return array<int, string>
     */
    public function hiddenViewsForRole(string $role): array
    {
        $overrides = $this->overrides();
        $hidden = $overrides[$role]['hidden_views'] ?? [];

        return is_array($hidden) ? array_values(array_unique(array_map('strval', $hidden))) : [];
    }

    public function isViewVisible(string $role, string $view, array $permissions): bool
    {
        return in_array($view, $this->allowedViewsForRole($role, $permissions), true);
    }

    public function setViewVisible(string $role, string $view, bool $visible): void
    {
        if ($view === 'dashboard' || ! in_array($view, $this->allViews(), true)) {
            return;
        }

        $overrides = $this->overrides();
        $hidden = $overrides[$role]['hidden_views'] ?? [];
        $hidden = is_array($hidden) ? array_values(array_unique(array_map('strval', $hidden))) : [];

        if ($visible) {
            $hidden = array_values(array_diff($hidden, [$view]));
        } elseif (! in_array($view, $hidden, true)) {
            $hidden[] = $view;
        }

        $overrides[$role] = [
            'hidden_views' => array_values($hidden),
            'updated_at' => now()->toDateTimeString(),
        ];

        app(SettingService::class)->set(
            self::SETTING_KEY,
            json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            true,
            ['type' => 'json'],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function overrides(): array
    {
        $raw = app(SettingService::class)->get(self::SETTING_KEY, '{}');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
