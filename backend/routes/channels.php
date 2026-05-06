<?php

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

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('orders', function ($user) {
    return in_array($user->role->value ?? $user->role, ['admin', 'driver'], true);
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('order.{id}', function ($user, $id) {
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

    return (int) optional($order->driver)->user_id === (int) $user->id;
});

Broadcast::channel('chat.{id}', function ($user, $id) {
    $chat = \App\Models\ChatConversation::query()->find($id);

    if (! $chat) {
        return false;
    }

    $role = $user->role->value ?? $user->role;

    return in_array($role, ['admin', 'operator', 'gm', 'manager', 'spv'], true)
        || in_array((int) $user->id, array_filter([
            $chat->customer_id,
            $chat->driver_id,
            $chat->operator_id,
        ]), true);
});

Broadcast::channel('chat.order.{orderId}', function ($user, $orderId) {
    $order = \App\Models\Order::query()->with('driver')->find($orderId);

    if (! $order) {
        return false;
    }

    $role = $user->role->value ?? $user->role;

    return in_array($role, ['admin', 'operator', 'gm', 'manager', 'spv'], true)
        || (int) $order->user_id === (int) $user->id
        || (int) optional($order->driver)->user_id === (int) $user->id;
});

Broadcast::channel('chat.operator.{userId}', function ($user, $userId) {
    $role = $user->role->value ?? $user->role;

    return (int) $user->id === (int) $userId
        || in_array($role, ['admin', 'operator', 'gm', 'manager', 'spv'], true);
});
