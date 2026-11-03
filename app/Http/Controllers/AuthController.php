<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    // Allow a short burst of attempts, then lock out per email+IP for a minute.
    private const MAX_ATTEMPTS = 5;

    public function showLogin()
    {
        return Inertia::render('Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']) . '|' . $request->ip());
        $this->ensureNotRateLimited($throttleKey);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // A failed attempt counts toward the lockout; decays after 60s.
            RateLimiter::hit($throttleKey);

            // Store the attempted email in context, never as the actor — the
            // attempt is unauthenticated, whoever it claimed to be.
            \App\Support\Audit::record('auth.login_failed', null, ['email' => $credentials['email']]);

            return back()->withErrors(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        \App\Support\Audit::record('auth.login', $request->user());

        return redirect()->intended(route('workspaces.index'));
    }

    /** Stop password guessing: block once too many recent attempts pile up. */
    private function ensureNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    public function logout(Request $request)
    {
        \App\Support\Audit::record('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
