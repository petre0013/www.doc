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
            ->get(['id', 'name', 'email', 'avatar_color'])
            ->map(fn (User $user) => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'avatar_color' => $user->avatar_color,
                'role'         => $user->getRoleNames()->first(),
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
            'password' => ['required', 'confirmed', Password::min(8)],
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
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($this->isLastAdmin($user)) {
            return back()->with('error', 'There must be at least one admin.');
        }

        // Snapshot identity in context: the FK on any of their past events is
        // about to be scrubbed to NULL by the delete cascade.
        Audit::record('user.deleted', null, [
            'user_id' => $user->id,
            'name'    => $user->name,
            'email'   => $user->email,
        ]);

        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    /** True when $user is an admin and the only one left. */
    protected function isLastAdmin(User $user): bool
    {
        return $user->hasRole('admin') && User::role('admin')->count() === 1;
    }
}
