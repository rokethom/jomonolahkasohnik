<?php

namespace App\Services;

use App\Enums\UserRole;
use Throwable;

class RolePermissionSettingService
{
    public const EDIT_TARIF_ALLOWED_ROLES_KEY = 'edit_tarif_allowed_roles';
    public const DEFAULT_EDIT_TARIF_ROLES = [
        'hrd',
        'manager',
        'spv',
        'operator',
        'eksekutor',
    ];

    public const CONFIGURABLE_EDIT_TARIF_ROLES = [
        'hrd',
        'manager',
        'spv',
        'operator',
        'eksekutor',
    ];

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function editTarifAllowedRoles(): array
    {
        try {
            $raw = $this->settings->get(self::EDIT_TARIF_ALLOWED_ROLES_KEY, json_encode(self::DEFAULT_EDIT_TARIF_ROLES));
        } catch (Throwable) {
            return self::DEFAULT_EDIT_TARIF_ROLES;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeEditTarifRoles(is_array($decoded) ? $decoded : self::DEFAULT_EDIT_TARIF_ROLES);
    }

    public function normalizeEditTarifRoles(array $roles): array
    {
        return collect($roles)
            ->map(fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter(fn (string $role): bool => in_array($role, self::CONFIGURABLE_EDIT_TARIF_ROLES, true))
            ->unique()
            ->values()
            ->all();
    }

    public function roleHasEditTarif(UserRole|string|null $role): bool
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtolower((string) $role);

        if (in_array($roleValue, [UserRole::Admin->value, UserRole::GM->value], true)) {
            return true;
        }

        return in_array($roleValue, $this->editTarifAllowedRoles(), true);
    }
}
