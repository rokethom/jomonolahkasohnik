<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user(), 401);

        if (in_array($request->user()->role, [UserRole::Admin, UserRole::GM], true)) {
            return $next($request);
        }

        abort_unless($request->user()->hasPermission($permission), 403);

        return $next($request);
    }
}
