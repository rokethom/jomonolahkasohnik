<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccessSettingService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

$broadcastRole = static fn (User $user): string => $user->role instanceof UserRole
    ? $user->role->value
    : strtolower((string) $user->role);

$broadcastScopeIds = static function (User $user) use ($broadcastRole): ?array {
    $role = $broadcastRole($user);

    if (app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($user->role) || $role === UserRole::Operator->value) {
        return null;
    }

    if (! in_array($role, [UserRole::HRD->value, UserRole::Manager->value, UserRole::SPV->value, UserRole::Eksekutor->value], true)) {
        return [];
    }

    $user->loadMissing('branchScopes:id');

    $branchIds = $user->branchScopes
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    if ($branchIds === [] && $user->branch_id !== null) {
        $branchIds[] = (int) $user->branch_id;
    }

    return Branch::expandToOperationalAreaIds($branchIds);
};

$canSeeBranch = static function (User $user, ?int $branchId) use ($broadcastScopeIds): bool {
    $scopeIds = $broadcastScopeIds($user);

    return $scopeIds === null || ($branchId !== null && in_array((int) $branchId, $scopeIds, true));
};

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('orders', function ($user) {
    return in_array($user->role->value ?? $user->role, ['admin', 'gm', 'hrd', 'manager', 'spv', 'operator', 'eksekutor', 'driver'], true);
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('order.{id}', function ($user, $id) use ($canSeeBranch) {
    $order = \App\Models\Order::query()->with('driver')->find($id);

    if (! $order) {
        return false;
    }

    if (($user->role->value ?? $user->role) === 'admin') {
        return true;
    }

    if ((int) $order->user_id === (int) $user->id) {
        return true;
    }

    if ((int) optional($order->driver)->user_id === (int) $user->id) {
        return true;
    }

    $role = $user->role->value ?? $user->role;

    return in_array($role, ['gm', 'hrd', 'manager', 'spv', 'operator', 'eksekutor'], true)
        && $canSeeBranch($user, $order->branch_id);
});

Broadcast::channel('chat.{id}', function ($user, $id) use ($canSeeBranch) {
    $chat = \App\Models\ChatConversation::query()
        ->with(['order:id,branch_id', 'customer:id,branch_id', 'driver:id,branch_id'])
        ->find($id);

    if (! $chat) {
        return false;
    }

    $role = $user->role->value ?? $user->role;

    if (in_array((int) $user->id, array_filter([
            $chat->customer_id,
            $chat->driver_id,
            $chat->operator_id,
        ]), true)) {
        return true;
    }

    $branchId = $chat->branch_id ?? $chat->order?->branch_id ?? $chat->customer?->branch_id ?? $chat->driver?->branch_id;

    return in_array($role, ['admin', 'operator', 'eksekutor', 'gm', 'manager', 'spv', 'hrd'], true)
        && $canSeeBranch($user, $branchId);
});

Broadcast::channel('chat.order.{orderId}', function ($user, $orderId) use ($canSeeBranch) {
    $order = \App\Models\Order::query()->with('driver')->find($orderId);

    if (! $order) {
        return false;
    }

    $role = $user->role->value ?? $user->role;

    if ((int) $order->user_id === (int) $user->id || (int) optional($order->driver)->user_id === (int) $user->id) {
        return true;
    }

    return in_array($role, ['admin', 'operator', 'eksekutor', 'gm', 'manager', 'spv', 'hrd'], true)
        && $canSeeBranch($user, $order->branch_id);
});

Broadcast::channel('chat.operator.{userId}', function ($user, $userId) use ($canSeeBranch) {
    $role = $user->role->value ?? $user->role;

    if ((int) $user->id === (int) $userId) {
        return true;
    }

    $target = \App\Models\User::query()->select(['id', 'branch_id'])->find($userId);

    return $target !== null
        && in_array($role, ['admin', 'operator', 'eksekutor', 'gm', 'manager', 'spv', 'hrd'], true)
        && $canSeeBranch($user, $target->branch_id);
});
