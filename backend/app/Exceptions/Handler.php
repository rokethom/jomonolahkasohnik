<?php

namespace App\Exceptions;

use App\Services\HermesSafetyAssistantService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            if (app()->runningInConsole()) {
                return;
            }

            try {
                $request = request();

                if (! $request instanceof Request) {
                    return;
                }

                if (! $this->shouldReportToHermes($e, $request)) {
                    return;
                }

                app(HermesSafetyAssistantService::class)->analyzeError([
                    'source' => 'failed_request',
                    'service' => config('app.name', 'jojo-backend'),
                    'environment' => app()->environment(),
                    'error_message' => $e->getMessage(),
                    'stack_trace' => mb_substr($e->getTraceAsString(), 0, 8000),
                    'request' => [
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'ip' => $request->ip(),
                        'user_id' => $request->user()?->id,
                        'input' => $this->safeRequestInput($request),
                    ],
                ], 'safety_failed_request');
            } catch (Throwable $hermesException) {
                Log::channel('hermes')->warning('hermes_safety.exception_report_failed', [
                    'error' => $hermesException->getMessage(),
                ]);
            }
        });
    }

    private function shouldReportToHermes(Throwable $exception, Request $request): bool
    {
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return false;
        }

        $fingerprint = sha1(implode('|', [
            $request->method(),
            $request->path(),
            $exception::class,
            mb_substr($exception->getMessage(), 0, 240),
        ]));

        return Cache::add("hermes_safety:failed_request:{$fingerprint}", true, now()->addMinutes(5));
    }

    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|Response|SymfonyResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return redirect()->guest(route('filament.admin.auth.login'));
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRequestInput(Request $request): array
    {
        $input = Arr::except($request->input(), [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'credential',
            'api_key',
            'secret',
            'key',
            'authorization',
        ]);

        return collect($input)
            ->take(20)
            ->map(function (mixed $value): mixed {
                if (is_scalar($value) || $value === null) {
                    return mb_substr((string) $value, 0, 500);
                }

                return '[complex input omitted]';
            })
            ->all();
    }
}
