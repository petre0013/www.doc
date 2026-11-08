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

    // Second, per-email-only limit. The email+IP key alone can be dodged by
    // anyone who can change their apparent IP (a botnet, or a forged
    // X-Forwarded-For if a proxy is misconfigured), so cap the total failures
    // against one account regardless of where they come from.
    private const MAX_ATTEMPTS_PER_EMAIL = 20;
    private const EMAIL_DECAY_SECONDS = 900;

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

        $email       = Str::transliterate(Str::lower($credentials['email']));
        $throttleKey = $email . '|' . $request->ip();
        $emailKey    = self::emailThrottleKey($email);
        $this->ensureNotRateLimited($throttleKey, self::MAX_ATTEMPTS);
        $this->ensureNotRateLimited($emailKey, self::MAX_ATTEMPTS_PER_EMAIL);

        Auth::guard()->setRememberDuration((int) config('auth.remember_minutes'));

        // Same response as a wrong password, so account state isn't disclosed.
        if (! Auth::attemptWhen($credentials, fn ($user) => $user->isActive(), $request->boolean('remember'))) {
            // A failed attempt counts toward both lockouts: email+IP decays
            // after 60s, the per-email total after 15 minutes.
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($emailKey, self::EMAIL_DECAY_SECONDS);

            // Store the attempted email in context, never as the actor — the
            // attempt is unauthenticated, whoever it claimed to be.
            \App\Support\Audit::record('auth.login_failed', null, ['email' => $credentials['email']]);

            return back()->withErrors(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($emailKey);
        $request->session()->regenerate();

        // Passwords are hashed, so sign-in is the only moment a short one is visible.
        if (mb_strlen($credentials['password']) < \App\Support\PasswordPolicy::MIN_LENGTH) {
            $request->user()->forceFill(['must_change_password' => true])->save();
        }

        \App\Support\Audit::record('auth.login', $request->user());

        return redirect()->intended(route('workspaces.index'));
    }

    /** Rate-limiter key for the per-email (any IP) failure count. */
    public static function emailThrottleKey(string $email): string
    {
        return 'login-email|' . Str::transliterate(Str::lower($email));
    }

    /** Stop password guessing: block once too many recent attempts pile up. */
    private function ensureNotRateLimited(string $throttleKey, int $maxAttempts): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
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
