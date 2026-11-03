<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

/**
 * Backups are instance-administration: admin-only across the board. The admin
 * middleware already guards the routes; these keep the boundary on the server
 * via $this->authorize(), per the app's policy convention.
 */
class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Backup $backup): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Backup $backup): bool
    {
        return $user->hasRole('admin') && $backup->status === 'done';
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $user->hasRole('admin');
    }
}
