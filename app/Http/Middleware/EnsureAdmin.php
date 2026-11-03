<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the instance-administration area. Only admins may manage apps, users and
 * other platform-wide settings; everyone else gets a 403. Used as `admin`.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        return $next($request);
    }
}
