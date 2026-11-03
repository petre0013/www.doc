<?php

namespace App\Http\Controllers;

use App\Support\MailSettings;
use App\Support\Smtp\ErrorClassifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Forgot-password → emailed reset link → set a new password. Backed by
 * Laravel's password broker (the `password_reset_tokens` table) and delivered
 * through the SMTP settings configured in the setup wizard / admin Email tab.
 *
 * The "send link" response is deliberately generic (it never reveals whether an
 * email is registered) to avoid account enumeration — in keeping with the
 * login throttling already in AuthController.
 */
class PasswordResetController extends Controller
{
    public function showForgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Fire the broker but ignore its specific status, so timing/response
        // can't be used to enumerate accounts. An SMTP outage must not leak
        // through as a 500 either — log the classified diagnosis (host:port,
        // failure category) and keep the generic response. Only TRANSPORT
        // failures are absorbed; genuine bugs still surface.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (TransportExceptionInterface $e) {
            report(new \RuntimeException(
                'Password-reset email failed: '.ErrorClassifier::message($e, MailSettings::get()),
                previous: $e,
            ));
        }

        return back()->with('status', 'If that email matches an account, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                // The User model's `hashed` cast hashes on set.
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                // Actor is the user themself — the reset link proved ownership.
                \App\Support\Audit::record('auth.password_reset', $user, [], $user->id);
            },
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('login')->with('status', 'Your password has been reset — you can sign in now.');
        }

        throw ValidationException::withMessages(['email' => [__($status)]]);
    }
}
