<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Profile', [
            'user' => Auth::user()->only(['id', 'name', 'email', 'avatar_color']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email,' . Auth::id()],
            'avatar_color' => ['nullable', 'string', 'in:sage,sky,amber,rose,purple,slate'],
        ]);

        Auth::user()->update($validated);

        return back()->with('profile_success', true);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        Auth::user()->update(['password' => $request->input('password')]);

        \App\Support\Audit::record('auth.password_changed', Auth::user());

        return back()->with('password_success', true);
    }
}
