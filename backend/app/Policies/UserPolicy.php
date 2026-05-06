<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return in_array($this->roleOf($user), [UserRole::Admin, UserRole::GM], true) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('create_user');
    }

    public function view(User $user, User $model): bool
    {
        $role = $this->roleOf($user);

        if ($role === UserRole::Manager) {
            return $this->roleOf($model) !== UserRole::Admin;
        }

        if ($role?->canManageUsers()) {
            return $role->canManageRole($this->roleOf($model) ?? UserRole::Customer);
        }

        return $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('create_user');
    }

    public function update(User $user, User $model): bool
    {
        $role = $this->roleOf($user);

        return $role?->canManageRole($this->roleOf($model) ?? UserRole::Customer) === true;
    }

    public function delete(User $user, User $model): bool
    {
        return $this->update($user, $model) && ! $user->is($model);
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    private function roleOf(User $user): ?UserRole
    {
        return $user->role instanceof UserRole
            ? $user->role
            : UserRole::tryFrom((string) $user->role);
    }
}
