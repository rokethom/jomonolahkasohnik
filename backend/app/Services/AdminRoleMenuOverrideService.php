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
                'sticky-notes' => 'Sticky Notes',
                'manual-order' => 'Manual Order',
            ],
            'Management' => [
                'users' => 'Users',
                'drivers' => 'Driver Management',
            ],
            'Home CMS' => [
                'banners' => 'Banners',
                'home-sections' => 'Home Sections',
                'home-items' => 'Home Items',
                'announcements' => 'Announcements',
            ],
            'Pricing CMS' => [
                'master-pricing' => 'Master Pricing',
                'price-settings' => 'Price Settings',
                'pricing' => 'Pricing & Policy',
                'pricing-keyword-rules' => 'Pricing Keyword Rules',
                'ring-pricing' => 'Master Ring',
                'zone-pricing' => 'Zone Pricing Rules',
                'zone-pricing-tester' => 'Zone Pricing Tester',
            ],
            'JojoBot CMS' => [
                'keyword-parsers' => 'Keyword Parsers',
                'order-crew-rules' => 'Order Crew Rules',
            ],
            'Area' => [
                'branches' => 'Branches',
                'geofence' => 'Geofence',
                'locations' => 'Location Logs',
            ],
            'System CMS' => [
                'settings' => 'System Settings',
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

        $views = $this->baseAllowedViewsForRole($roleValue, $permissions);
        $hidden = $this->hiddenViewsForRole($roleValue);
        $extra = $this->extraViewsForRole($roleValue);

        return collect([...$views, ...$extra])
            ->unique()
            ->reject(fn (string $view): bool => $view !== 'dashboard' && in_array($view, $hidden, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<int, string>
     */
    public function baseAllowedViewsForRole(string $roleValue, array $permissions): array
    {
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
            $views[] = 'master-pricing';
            $views[] = 'price-settings';
            $views[] = 'pricing';
            $views[] = 'keyword-parsers';
            $views[] = 'pricing-keyword-rules';
            $views[] = 'ring-pricing';
            $views[] = 'zone-pricing';
            $views[] = 'zone-pricing-tester';
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

        if ($permissions['can_use_internal_notes'] ?? false) {
            $views[] = 'sticky-notes';
        }

        if ($permissions['can_create_manual_order'] ?? false) {
            $views[] = 'manual-order';
        }

        if ($permissions['can_manage_system_settings'] ?? false) {
            $views[] = 'settings';
        }

        if ($permissions['can_manage_cms'] ?? false) {
            $views[] = 'banners';
            $views[] = 'home-sections';
            $views[] = 'home-items';
            $views[] = 'announcements';
        }

        if (in_array($roleValue, [
            UserRole::Manager->value,
            UserRole::SPV->value,
            UserRole::Operator->value,
            UserRole::Eksekutor->value,
        ], true)) {
            $views[] = 'locations';
        }

        return array_values(array_unique($views));
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

    /**
     * @return array<int, string>
     */
    public function extraViewsForRole(string $role): array
    {
        $overrides = $this->overrides();
        $extra = $overrides[$role]['extra_views'] ?? [];

        return is_array($extra) ? array_values(array_unique(array_map('strval', $extra))) : [];
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
        $extra = $overrides[$role]['extra_views'] ?? [];
        $extra = is_array($extra) ? array_values(array_unique(array_map('strval', $extra))) : [];

        if ($visible) {
            $hidden = array_values(array_diff($hidden, [$view]));
            if (! in_array($view, $extra, true)) {
                $extra[] = $view;
            }
        } elseif (! in_array($view, $hidden, true)) {
            $hidden[] = $view;
            $extra = array_values(array_diff($extra, [$view]));
        }

        $overrides[$role] = [
            'hidden_views' => array_values($hidden),
            'extra_views' => array_values($extra),
            'updated_at' => now()->toDateTimeString(),
        ];

        app(SettingService::class)->set(
            self::SETTING_KEY,
            json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            true,
            ['type' => 'json'],
        );
    }

    public function resetRole(string $role): void
    {
        $overrides = $this->overrides();
        unset($overrides[$role]);

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
