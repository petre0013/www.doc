<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The global SMTP settings, used both by the setup wizard's mail step and the
 * admin Email tab. Password is optional: a blank field preserves the stored
 * (encrypted) password, mirroring the backups settings convention.
 */
class MailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Setup gate / admin middleware on the route is the boundary.
        return true;
    }

    public function rules(): array
    {
        return [
            'host'         => ['required', 'string', 'max:255'],
            'port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption'   => ['required', 'in:tls,ssl,none'],
            'verify_peer'  => ['boolean'],
            'username'     => ['nullable', 'string', 'max:255'],
            'password'     => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name'    => ['nullable', 'string', 'max:255'],
        ];
    }
}
