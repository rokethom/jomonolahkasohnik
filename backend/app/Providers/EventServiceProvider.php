<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use App\Events\DriverAccepted;
use App\Events\OrderCreated;
use App\Events\OrderPriceUpdated;
use App\Events\OrderStatusUpdated;
use App\Listeners\BroadcastOrderFeedChanged;
use App\Listeners\ReportFailedJobToHermes;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        JobFailed::class => [
            ReportFailedJobToHermes::class,
        ],
        OrderCreated::class => [
            BroadcastOrderFeedChanged::class,
        ],
        OrderStatusUpdated::class => [
            BroadcastOrderFeedChanged::class,
        ],
        DriverAccepted::class => [
            BroadcastOrderFeedChanged::class,
        ],
        OrderPriceUpdated::class => [
            BroadcastOrderFeedChanged::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User || ! str_starts_with((string) request()->path(), 'admin')) {
                return;
            }

            AuditLog::query()->create([
                'user_id' => $event->user->id,
                'action' => 'admin_login',
                'subject_type' => User::class,
                'subject_id' => $event->user->id,
                'subject_label' => $event->user->email,
                'metadata' => [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            ]);
        });

        Event::listen(Failed::class, function (Failed $event): void {
            if (! str_starts_with((string) request()->path(), 'admin')) {
                return;
            }

            AuditLog::query()->create([
                'user_id' => $event->user?->id,
                'action' => 'failed_admin_login',
                'subject_type' => User::class,
                'subject_id' => $event->user?->id,
                'subject_label' => $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown',
                'metadata' => [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            ]);
        });
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
