<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Ends the session of an account deactivated while it was signed in. */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($request->user()?->must_change_password && ! $request->routeIs('settings.*', 'logout')) {
            return redirect()->route('settings.profile')->with('error', 'Your password is too short. Please choose one with at least 12 characters.');
        }

        return $next($request);
    }
}
