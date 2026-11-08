<?php

namespace App\Policies;

use App\Models\ConversionJob;
use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use Illuminate\Auth\Access\Response;

class ConversionJobPolicy
{
    use ChecksWorkspaceAccess;

    public function create(User $user): bool { return $user->hasAnyRole(['admin', 'editor']); }

    public function view(User $user, ConversionJob $job): Response|bool
    {
        $workspaceId = Document::withTrashed()->whereKey($job->document_id)->value('workspace_id');

        return $this->inWorkspace($user, $workspaceId, ['admin', 'editor', 'viewer']);
    }
}
