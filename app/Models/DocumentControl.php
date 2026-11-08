<?php

namespace App\Models;

use App\Support\AuditChain;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'doc_number', 'owner_id', 'approver_id', 'classification', 'status', 'review_interval_months', 'next_review_at', 'approved_version_id', 'approved_at', 'pending_version_id', 'submitted_by_id', 'ack_required'])]
class DocumentControl extends Model
{
    public const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    protected function casts(): array
    {
        return [
            'next_review_at' => 'date',
            'approved_at'    => 'datetime',
            'ack_required'   => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'approved_version_id');
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'retired' && $this->next_review_at !== null && $this->next_review_at->lt(today());
    }

    /** Owners and admins may change the control details and retire the page. */
    public function isManagedBy(User $user): bool
    {
        return $user->hasRole('admin') || $this->owner_id === $user->id;
    }

    /** Fingerprint of exactly what was signed off. */
    public static function hashVersion(DocumentVersion $version): string
    {
        return hash('sha256', json_encode(
            [$version->title, AuditChain::canonical($version->content)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * The approved version a reader should get instead of the working draft, or
     * null when the live page is the right one. People who can edit see the draft.
     */
    public function versionForReader(User $user): ?DocumentVersion
    {
        if ($this->approved_version_id === null || $user->can('update', $this->document)) {
            return null;
        }

        return $this->approvedVersion;
    }
}
