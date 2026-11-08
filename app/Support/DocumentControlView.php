<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentControl;
use App\Models\DocumentControlEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/** Shapes a page's document-control state for the page panel. */
class DocumentControlView
{
    public static function for(Document $document, User $user, bool $servingApproved): ?array
    {
        $control = $document->control;
        if (! $control) {
            return null;
        }

        $control->loadMissing('owner:id,name', 'approver:id,name', 'approvedVersion');
        $latestId = $document->versions()->latest('id')->value('id');
        $approved = $control->approvedVersion;
        $lastSignOff = $approved ? DocumentControlEvent::where('document_version_id', $approved->id)
            ->where('action', 'approved')->latest('id')->first() : null;

        $canEdit = $user->can('update', $document);
        $isApprover = $control->approver_id === $user->id;
        $manages = $control->isManagedBy($user);

        $acknowledged = $approved ? DocumentAcknowledgement::where('document_version_id', $approved->id)->pluck('user_id') : collect();
        $audience = $control->ack_required ? self::eligibleUsers($document) : collect();

        return [
            'ack' => [
                'required' => $control->ack_required,
                'mine'     => $acknowledged->contains($user->id),
                'can'      => $control->ack_required && $approved !== null,
                'done'     => $audience->whereIn('id', $acknowledged)->count(),
                'total'    => $audience->count(),
                'missing'  => $manages ? $audience->whereNotIn('id', $acknowledged)->pluck('name')->values() : [],
            ],
            'doc_number'             => $control->doc_number,
            'owner'                  => $control->owner?->only('id', 'name'),
            'approver'               => $control->approver?->only('id', 'name'),
            'owner_id'               => $control->owner_id,
            'approver_id'            => $control->approver_id,
            'classification'         => $control->classification,
            'status'                 => $control->status,
            'review_interval_months' => $control->review_interval_months,
            'next_review_at'         => $control->next_review_at?->toDateString(),
            'overdue'                => $control->isOverdue(),
            'approved_version_id'    => $control->approved_version_id,
            'approved_at'            => $control->approved_at?->toIso8601String(),
            'pending_version_id'     => $control->pending_version_id,
            'has_unapproved_changes' => $approved !== null && $latestId !== $approved->id,
            'serving_approved'       => $servingApproved,
            // False means the stored version no longer matches what was signed off.
            'hash_intact'            => $lastSignOff === null || hash_equals($lastSignOff->content_hash, DocumentControl::hashVersion($approved)),
            'can' => [
                'manage'  => $manages,
                'submit'  => $canEdit && $control->status !== 'in_review' && $control->approver_id !== null && $latestId !== null && $latestId !== $control->approved_version_id,
                'decide'  => $isApprover && $control->status === 'in_review',
                'review'  => ($manages || $isApprover) && $control->status === 'approved',
                'retire'  => $manages && $control->status !== 'retired',
            ],
            'events' => DocumentControlEvent::where('document_id', $document->id)->latest('id')->limit(10)->get()
                ->map(fn (DocumentControlEvent $e) => [
                    'id'         => $e->id,
                    'action'     => $e->action,
                    'actor'      => $e->actor_name,
                    'comment'    => $e->comment,
                    'version_id' => $e->document_version_id,
                    'hash'       => $e->content_hash,
                    'created_at' => $e->created_at->toIso8601String(),
                ]),
        ];
    }

    /** People who could be an owner or approver of this page. */
    public static function eligibleUsers(Document $document): Collection
    {
        return User::whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $user) => $user->canAccessWorkspace($document->workspace_id))
            ->values();
    }
}
