<?php

use App\Services\SettingService;
use App\Models\User;

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingService::class)->get($key, $default);
    }
}

if (! function_exists('can_permission')) {
    function can_permission(?User $user, string $permission): bool
    {
        return $user?->hasPermission($permission) === true;
    }
}

if (! function_exists('can')) {
    function can(?User $user, string $permission): bool
    {
        return can_permission($user, $permission);
    }
}
