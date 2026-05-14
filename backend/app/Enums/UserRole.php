<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case GM = 'gm';
    case HRD = 'hrd';
    case Manager = 'manager';
    case SPV = 'spv';
    case Operator = 'operator';
    case Eksekutor = 'eksekutor';
    case WebAdmin = 'web_admin';
    case CmsEditor = 'cms_editor';
    case Driver = 'driver';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::GM => 'General Manager',
            self::HRD => 'HRD',
            self::Manager => 'Manager',
            self::SPV => 'SPV',
            self::Operator => 'Operator',
            self::Eksekutor => 'Eksekutor',
            self::WebAdmin => 'Web Admin',
            self::CmsEditor => 'CMS Editor',
            self::Driver => 'Driver',
            self::Customer => 'Customer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::GM => 'warning',
            self::Manager => 'orange',
            self::HRD => 'purple',
            self::SPV => 'info',
            self::Operator => 'cyan',
            self::Eksekutor => 'sky',
            self::WebAdmin => 'info',
            self::CmsEditor => 'info',
            self::Driver => 'success',
            self::Customer => 'gray',
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [
            self::Admin,
            self::GM,
            self::HRD,
            self::Manager,
            self::SPV,
            self::Operator,
            self::Eksekutor,
            self::WebAdmin,
            self::CmsEditor,
        ], true);
    }

    public function canManageUsers(): bool
    {
        return in_array($this, [self::Admin, self::GM, self::HRD, self::Manager, self::SPV, self::Operator, self::Eksekutor], true);
    }

    public function assignableRoles(): array
    {
        return match ($this) {
            self::Admin => self::cases(),
            self::GM => self::cases(),
            self::HRD => [self::Manager, self::SPV, self::Operator, self::Eksekutor, self::Driver],
            self::Manager => [self::Manager, self::SPV, self::Operator, self::Eksekutor, self::Driver],
            self::SPV => [self::Operator, self::Eksekutor, self::Driver],
            self::Operator, self::Eksekutor => [self::Driver],
            default => [],
        };
    }

    public function canManageRole(self $targetRole): bool
    {
        if (in_array($this, [self::Admin, self::GM], true)) {
            return true;
        }

        return in_array($targetRole, $this->assignableRoles(), true);
    }

    public static function options(?self $actorRole = null): array
    {
        $roles = $actorRole ? $actorRole->assignableRoles() : self::cases();

        return collect($roles)
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }
}
