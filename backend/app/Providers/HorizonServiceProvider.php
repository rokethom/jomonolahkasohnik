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

        $rawRole = method_exists($user, 'getRawOriginal')
            ? $user->getRawOriginal('role')
            : ($user->role ?? '');

        $role = strtolower(trim((string) ($rawRole instanceof UserRole ? $rawRole->value : $rawRole)));

        return in_array($role, [UserRole::Admin->value, 'admin', 'sa', 'superadmin', 'super_admin'], true);
    }
}
