<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ($user->role?->value ?? $user->role) !== 'customer') {
            return $next($request);
        }

        if (filled($user->name) && filled($user->phone) && filled($user->address)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Lengkapi profile terlebih dahulu.',
            'code' => 'profile_setup_required',
            'redirect_to' => '/profile/setup',
            'required_fields' => ['name', 'phone', 'address'],
        ], 409);
    }
}
