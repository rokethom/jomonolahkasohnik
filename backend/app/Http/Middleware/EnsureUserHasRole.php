<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        $role = $user->role instanceof UserRole
            ? $user->role->value
            : (string) $user->role;

        abort_unless(in_array($role, $roles, true), 403);

        return $next($request);
    }
}
