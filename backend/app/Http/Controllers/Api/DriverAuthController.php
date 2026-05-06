<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DriverGoogleLoginException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DriverGoogleLoginRequest;
use App\Http\Resources\DriverResource;
use App\Services\DriverGoogleAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DriverAuthController extends Controller
{
    public function google(DriverGoogleLoginRequest $request, DriverGoogleAuthService $auth): JsonResponse
    {
        try {
            $result = $auth->login($request->validated('token'), $request);

            return response()->json([
                'success' => true,
                'token' => $result['token'],
                'driver' => new DriverResource($result['driver']),
            ]);
        } catch (DriverGoogleLoginException $exception) {
            Log::warning('driver_google_login.failed', [
                'reason' => $exception->reason(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        } catch (Throwable $exception) {
            Log::error('driver_google_login.unexpected_error', [
                'message' => $exception->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login Google driver gagal. Silakan coba lagi.',
            ], 500);
        }
    }
}
