<?php

namespace App\Services;

use App\Enums\UserRole;
use Throwable;

class AiDataAccessSettingService
{
    public const AI_DATA_ALLOWED_ROLES_KEY = 'ai_data_allowed_roles';

    public const CONFIGURABLE_ROLES = [
        'hrd',
        'manager',
        'spv',
        'operator',
        'eksekutor',
        'web_admin',
        'cms_editor',
    ];

    public const DEFAULT_ROLES = self::CONFIGURABLE_ROLES;

    public function __construct(private readonly SettingService $settings) {}

    /**
     * @return array<int, string>
     */
    public function allowedRoles(): array
    {
        try {
            $raw = $this->settings->get(self::AI_DATA_ALLOWED_ROLES_KEY, json_encode(self::DEFAULT_ROLES));
        } catch (Throwable) {
            return self::DEFAULT_ROLES;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeRoles(is_array($decoded) ? $decoded : self::DEFAULT_ROLES);
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return array<int, string>
     */
    public function normalizeRoles(array $roles): array
    {
        return collect($roles)
            ->map(fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter(fn (string $role): bool => in_array($role, self::CONFIGURABLE_ROLES, true))
            ->unique()
            ->values()
            ->all();
    }

    public function roleMayManageData(UserRole|string|null $role): bool
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtolower((string) $role);

        if (in_array($roleValue, [UserRole::Admin->value, UserRole::GM->value], true)) {
            return true;
        }

        return in_array($roleValue, $this->allowedRoles(), true);
    }

    public function setRoleAllowed(string $role, bool $allowed): void
    {
        $roles = $this->allowedRoles();
        $role = strtolower(trim($role));

        if (! in_array($role, self::CONFIGURABLE_ROLES, true)) {
            return;
        }

        $roles = $allowed
            ? [...$roles, $role]
            : array_values(array_diff($roles, [$role]));

        $this->settings->set(
            self::AI_DATA_ALLOWED_ROLES_KEY,
            json_encode($this->normalizeRoles($roles)),
            true,
            ['type' => 'json'],
        );
    }
}
