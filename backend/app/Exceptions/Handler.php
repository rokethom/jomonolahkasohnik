<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
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
        //
    }

    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|Response|SymfonyResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return redirect()->guest(route(Route::has('filament.admin.auth.login')
            ? 'filament.admin.auth.login'
            : 'filament.admin.auth.login.fallback'));
    }

    public function render($request, Throwable $e): SymfonyResponse
    {
        $response = parent::render($request, $e);

        if ($request instanceof Request && $request->is('api/*')) {
            $origin = (string) $request->headers->get('Origin', '*');
            $response->headers->set('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
            $response->headers->set('Vary', trim($response->headers->get('Vary').' Origin'));
        }

        return $response;
    }

}
