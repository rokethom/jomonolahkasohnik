<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active || $user->is_suspended || ($user->suspended_until !== null && $user->suspended_until->isFuture())) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => $user->suspension_reason ?: 'Akun Anda sedang diblokir. Silakan hubungi admin.',
                'code' => 'account_blocked',
            ], 403);
        }

        return $next($request);
    }
}
