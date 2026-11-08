<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel — manage user accounts and their role. Roles are single-valued
 * here (admin / editor / viewer) to match how the rest of the app checks them.
 */
class UserController extends Controller
{
    protected const ROLES = ['admin', 'editor', 'viewer'];

    public function index(): Response
    {
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_color', 'deactivated_at'])
            ->map(fn (User $user) => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'avatar_color' => $user->avatar_color,
                'role'         => $user->getRoleNames()->first(),
                'active'       => $user->isActive(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', \App\Support\PasswordPolicy::rule()],
            'role'     => ['required', Rule::in(self::ROLES)],
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->assignRole($validated['role']);

        Audit::record('user.created', $user, [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $validated['role'],
        ]);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        // Never strip the last admin of administration — it would lock everyone out.
        if ($validated['role'] !== 'admin' && $this->isLastAdmin($user)) {
            return back()->with('error', 'There must be at least one admin.');
        }

        $previousRole = $user->getRoleNames()->first();
        $user->syncRoles([$validated['role']]);

        if ($previousRole !== $validated['role']) {
            Audit::record('user.role_changed', $user, [
                'name' => $user->name,
                'from' => $previousRole,
                'to'   => $validated['role'],
            ]);
        }

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($this->isLastAdmin($user)) {
            return back()->with('error', 'There must be at least one admin.');
        }

        // Deactivate rather than delete, so past events and versions stay
        // attributable to a real account.
        $user->forceFill(['deactivated_at' => now(), 'remember_token' => null])->save();

        Audit::record('user.deactivated', $user, ['name' => $user->name, 'email' => $user->email]);

        return back()->with('success', 'User deactivated.');
    }

    public function reactivate(User $user): RedirectResponse
    {
        $user->forceFill(['deactivated_at' => null])->save();

        Audit::record('user.reactivated', $user, ['name' => $user->name, 'email' => $user->email]);

        return back()->with('success', 'User reactivated.');
    }

    /** True when $user is the only active admin left. */
    protected function isLastAdmin(User $user): bool
    {
        return $user->isActive() && $user->hasRole('admin')
            && User::role('admin')->whereNull('deactivated_at')->count() === 1;
    }
}
