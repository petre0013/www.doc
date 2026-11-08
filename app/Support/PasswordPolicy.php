<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The one password rule used by every path that sets a password. */
class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public static function rule(): Password
    {
        $rule = Password::min(self::MIN_LENGTH);

        // Opt-in: sends a 5-character hash prefix to the Have I Been Pwned API,
        // so a self-hosted or air-gapped instance makes no outbound call by default.
        return config('auth.password_breach_check') ? $rule->uncompromised() : $rule;
    }
}
