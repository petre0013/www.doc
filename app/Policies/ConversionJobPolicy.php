<?php

namespace App\Policies;

use App\Models\ConversionJob;
use App\Models\User;

class ConversionJobPolicy
{
    public function create(User $user): bool { return $user->hasAnyRole(['admin', 'editor']); }
    public function view(User $user, ConversionJob $job): bool { return $user->hasAnyRole(['admin', 'editor', 'viewer']); }
}
