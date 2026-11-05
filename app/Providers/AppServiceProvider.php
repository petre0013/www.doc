<?php

namespace App\Providers;

use App\Support\MailSettings;
use App\Support\Setup;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::define('viewTrash', function ($user) {
            return $user->hasRole('admin');
        });

        \Illuminate\Support\Facades\Gate::define('emptyTrash', function ($user) {
            return $user->hasRole('admin');
        });

        // Password-reset email in the app's own mail style (mail/layout.blade.php)
        // instead of Laravel's stock notification template. Registered before the
        // settings-table guard below — it's static wiring, not DB-dependent.
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $app = config('app.name');

            // Build the link on APP_URL, never on the request's Host header:
            // nginx answers any Host (server_name _), so a forged
            // `Host: evil.example` on the forgot form would otherwise mail the
            // victim a link to the attacker's server, leaking the reset token
            // when they click it. Falls back to the request only when APP_URL
            // is unset (the docs require it in production).
            $root = rtrim((string) config('app.url'), '/');
            $url  = ($root !== '' ? $root : url(''))
                . route('password.reset', $token, false)
                . '?email=' . urlencode($notifiable->getEmailForPasswordReset());

            return (new \Illuminate\Notifications\Messages\MailMessage)
                ->subject("[{$app}] Reset your password")
                ->view('mail.reset-password', [
                    'appName' => $app,
                    'url'     => $url,
                    'expires' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire'),
                ]);
        });
        // Apply operator-configured, DB-stored instance settings over config.
        // Guarded on the settings table existing so the very first `migrate`
        // (which boots the app before the table is created) doesn't fail here.
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        // SMTP from the setup wizard / admin Email tab drives the default mailer,
        // so password resets and any app mail deliver without touching .env.
        MailSettings::applyToMailer();

        // The operator's instance name (set in the wizard) becomes app.name.
        config(['app.name' => Setup::instanceName()]);
    }
}
