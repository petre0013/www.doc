<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['admin', 'editor', 'viewer']); }
    public function view(User $user, Asset $asset): bool { return $user->hasAnyRole(['admin', 'editor', 'viewer']); }
    public function create(User $user): bool { return $user->hasAnyRole(['admin', 'editor']); }
    public function update(User $user, Asset $asset): bool { return $user->hasAnyRole(['admin', 'editor']); }
    public function delete(User $user, Asset $asset): bool { return $user->hasRole('admin'); }
}
