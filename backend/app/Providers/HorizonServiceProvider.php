<?php

namespace App\Providers;

use App\Enums\UserRole;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(fn ($request): bool => $this->canViewHorizon($request->user()));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => $this->canViewHorizon($user));
    }

    private function canViewHorizon($user = null): bool
    {
        if (! $user) {
            return false;
        }

        $role = $user->role instanceof UserRole
            ? $user->role->value
            : strtolower((string) ($user->role ?? ''));

        return in_array($role, [UserRole::Admin->value, 'superadmin', 'super_admin'], true)
            && (bool) ($user->is_active ?? true)
            && ! (bool) ($user->is_suspended ?? false);
    }
}
