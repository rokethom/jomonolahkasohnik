<?php

namespace App\Services;

use App\Enums\UserRole;
use Throwable;

class BranchAccessSettingService
{
    public const GLOBAL_ACCESS_ROLES_KEY = 'branch_global_access_roles';

    public const DEFAULT_GLOBAL_ACCESS_ROLES = [];

    public const CONFIGURABLE_ROLES = [
        'hrd',
        'manager',
        'spv',
        'operator',
        'eksekutor',
    ];

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function globalAccessRoles(): array
    {
        try {
            $raw = $this->settings->get(self::GLOBAL_ACCESS_ROLES_KEY, json_encode(self::DEFAULT_GLOBAL_ACCESS_ROLES));
        } catch (Throwable) {
            return self::DEFAULT_GLOBAL_ACCESS_ROLES;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeRoles(is_array($decoded) ? $decoded : self::DEFAULT_GLOBAL_ACCESS_ROLES);
    }

    public function normalizeRoles(array $roles): array
    {
        return collect($roles)
            ->map(fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter(fn (string $role): bool => in_array($role, self::CONFIGURABLE_ROLES, true))
            ->unique()
            ->values()
            ->all();
    }

    public function roleHasGlobalBranchAccess(UserRole|string|null $role): bool
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtolower((string) $role);

        if (in_array($roleValue, [UserRole::Admin->value, UserRole::GM->value], true)) {
            return true;
        }

        return in_array($roleValue, $this->globalAccessRoles(), true);
    }
}
