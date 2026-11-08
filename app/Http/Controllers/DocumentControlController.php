<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentControl;
use App\Models\DocumentControlEvent;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentControlController extends Controller
{
    /** The register: every controlled page, and what was in force on a chosen date. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $validated = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of'])->endOfDay() : now();
        $user = $request->user();

        $controls = DocumentControl::query()
            ->whereHas('document', fn ($q) => $q->accessibleBy($user))
            ->with('document:id,title,workspace_id', 'document.workspace:id,name', 'owner:id,name', 'approver:id,name')
            ->get();

        // A page's latest approval or retirement up to the date decides what was in force.
        $inForce = DocumentControlEvent::whereIn('document_id', $controls->pluck('document_id'))
            ->whereIn('action', ['approved', 'retired'])
            ->where('created_at', '<=', $asOf)
            ->orderByDesc('id')
            ->get()
            ->unique('document_id')
            ->keyBy('document_id');

        return Inertia::render('DocumentControl/Index', [
            'asOf'     => $validated['as_of'] ?? null,
            'overdue'  => $controls->filter->isOverdue()->count(),
            'controls' => $controls->sortBy(fn ($c) => mb_strtolower($c->document->title))->values()->map(function (DocumentControl $c) use ($inForce) {
                $event = $inForce->get($c->document_id);

                return [
                    'id'             => $c->id,
                    'document_id'    => $c->document_id,
                    'title'          => $c->document->title,
                    'workspace'      => $c->document->workspace?->name,
                    'doc_number'     => $c->doc_number,
                    'owner'          => $c->owner?->name,
                    'approver'       => $c->approver?->name,
                    'classification' => $c->classification,
                    'status'         => $c->status,
                    'next_review_at' => $c->next_review_at?->toDateString(),
                    'overdue'        => $c->isOverdue(),
                    'in_force'       => $event?->action === 'approved' ? [
                        'version_id'  => $event->document_version_id,
                        'approved_at' => $event->created_at->toIso8601String(),
                        'approved_by' => $event->actor_name,
                    ] : null,
                ];
            }),
        ]);
    }

    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        abort_if($document->control()->exists(), 409, 'This page is already under document control.');

        $validated = $this->validated($request, $document);

        $control = DB::transaction(function () use ($document, $validated) {
            $control = $document->control()->create($validated);
            $control->doc_number ??= 'DOC-' . str_pad((string) $control->id, 4, '0', STR_PAD_LEFT);
            $control->save();

            return $control;
        });

        Audit::record('control.enabled', $document, ['title' => $document->title, 'doc_number' => $control->doc_number]);

        return back()->with('success', "Placed under document control as {$control->doc_number}.");
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);
        $control = $document->control()->firstOrFail();
        abort_unless($control->isManagedBy($request->user()), 403);

        $validated = $this->validated($request, $document, $control);

        // Swapping the approver mid-review would let a submitter pick who signs.
        if ($control->status === 'in_review' && (int) $validated['approver_id'] !== $control->approver_id && ! $request->user()->hasRole('admin')) {
            return back()->with('error', 'Only an admin can change the approver during a review.');
        }

        $control->update($validated);

        if ($control->wasChanged()) {
            Audit::record('control.updated', $document, [
                'title'   => $document->title,
                'changed' => array_values(array_diff(array_keys($control->getChanges()), ['updated_at'])),
            ]);
        }

        return back()->with('success', 'Document control details updated.');
    }

    public function submit(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        return $this->transition($document, function (DocumentControl $control) use ($request, $document) {
            if ($control->status === 'in_review') {
                return 'This page is already in review.';
            }
            if ($control->approver_id === null) {
                return 'Choose an approver first.';
            }

            $latest = $document->versions()->latest('id')->first();
            if (! $latest) {
                return 'Add some content before submitting.';
            }
            if ($latest->id === $control->approved_version_id) {
                return 'The current content is already approved.';
            }

            $control->update(['status' => 'in_review', 'pending_version_id' => $latest->id, 'submitted_by_id' => $request->user()->id]);
            $this->record('submitted', $document, $control, $latest, $request->user(), $request->input('comment'));

            return 'Submitted for approval.';
        }, 'Submitted for approval.');
    }

    public function approve(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        return $this->transition($document, function (DocumentControl $control) use ($request, $document) {
            $user = $request->user();
            abort_unless($control->approver_id === $user->id, 403, 'Only the named approver can approve this page.');

            if ($control->status !== 'in_review' || ! $control->pending_version_id) {
                return 'This page is not in review.';
            }

            $latest = $document->versions()->latest('id')->first();
            if ($latest?->id !== $control->pending_version_id) {
                return 'The page changed after it was submitted. It must be submitted again.';
            }
            if ($user->id === $control->submitted_by_id || $user->id === $latest->created_by_id) {
                return 'An approver cannot approve their own submission or edit.';
            }

            $control->update([
                'status'              => 'approved',
                'approved_version_id' => $latest->id,
                'approved_at'         => now(),
                'pending_version_id'  => null,
                'submitted_by_id'     => null,
                'next_review_at'      => today()->addMonthsNoOverflow($control->review_interval_months),
            ]);
            $this->record('approved', $document, $control, $latest, $user, $request->input('comment'));

            return 'Approved.';
        }, 'Approved.');
    }

    public function reject(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        return $this->transition($document, function (DocumentControl $control) use ($request, $document, $validated) {
            abort_unless($control->approver_id === $request->user()->id, 403, 'Only the named approver can reject this page.');

            if ($control->status !== 'in_review') {
                return 'This page is not in review.';
            }

            $pending = DocumentVersion::find($control->pending_version_id);
            $control->update([
                'status'             => $control->approved_version_id ? 'approved' : 'draft',
                'pending_version_id' => null,
                'submitted_by_id'    => null,
            ]);
            $this->record('rejected', $document, $control, $pending, $request->user(), $validated['comment']);

            return 'Sent back with comments.';
        }, 'Sent back with comments.');
    }

    /** Confirm the approved version is still valid, starting a new review cycle. */
    public function review(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        return $this->transition($document, function (DocumentControl $control) use ($request, $document) {
            $user = $request->user();
            abort_unless($control->isManagedBy($user) || $control->approver_id === $user->id, 403);

            if ($control->status !== 'approved' || ! $control->approvedVersion) {
                return 'Only an approved page can be marked as reviewed.';
            }

            $control->update(['next_review_at' => today()->addMonthsNoOverflow($control->review_interval_months)]);
            $this->record('reviewed', $document, $control, $control->approvedVersion, $user, $request->input('comment'));

            return 'Review recorded.';
        }, 'Review recorded.');
    }

    public function retire(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        return $this->transition($document, function (DocumentControl $control) use ($request, $document) {
            abort_unless($control->isManagedBy($request->user()), 403);

            if ($control->status === 'retired') {
                return 'This page is already retired.';
            }

            $version = $control->approvedVersion;
            $control->update([
                'status'              => 'retired',
                'approved_version_id' => null,
                'pending_version_id'  => null,
                'submitted_by_id'     => null,
                'next_review_at'      => null,
            ]);
            $this->record('retired', $document, $control, $version, $request->user(), $request->input('comment'));

            return 'Retired.';
        }, 'Retired.');
    }

    /** A reader confirms they have read the approved version. */
    public function acknowledge(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);
        $control = $document->control()->firstOrFail();
        $user = $request->user();

        if (! $control->ack_required || ! $control->approved_version_id) {
            return back()->with('error', 'This page does not need acknowledging.');
        }

        $ack = DocumentAcknowledgement::firstOrCreate(
            ['document_version_id' => $control->approved_version_id, 'user_id' => $user->id],
            ['document_id' => $document->id, 'user_name' => $user->name, 'user_email' => $user->email, 'acknowledged_at' => now()],
        );

        if ($ack->wasRecentlyCreated) {
            Audit::record('control.acknowledged', $document, ['title' => $document->title, 'doc_number' => $control->doc_number, 'version_id' => $control->approved_version_id]);
        }

        return back()->with('success', 'Thanks, your acknowledgement is recorded.');
    }

    /** Run a workflow step under a row lock, so two people can't act at once. */
    private function transition(Document $document, callable $step, string $successMessage): RedirectResponse
    {
        $request = request();
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $message = DB::transaction(function () use ($document, $step) {
            $control = $document->control()->lockForUpdate()->firstOrFail();

            return $step($control);
        });

        return back()->with($message === $successMessage ? 'success' : 'error', $message);
    }

    private function record(string $action, Document $document, DocumentControl $control, ?DocumentVersion $version, User $actor, ?string $comment): void
    {
        $hash = $version ? DocumentControl::hashVersion($version) : null;

        DocumentControlEvent::create([
            'document_id'         => $document->id,
            'document_version_id' => $version?->id,
            'action'              => $action,
            'document_title'      => $version?->title ?? $document->title,
            'doc_number'          => $control->doc_number,
            'content_hash'        => $hash,
            'actor_id'            => $actor->id,
            'actor_name'          => $actor->name,
            'actor_email'         => $actor->email,
            'comment'             => $comment,
            'created_at'          => now(),
        ]);

        // The audit chain makes the sign-off and its hash tamper-evident.
        Audit::record("control.{$action}", $document, [
            'title'      => $document->title,
            'doc_number' => $control->doc_number,
            'version_id' => $version?->id,
            'hash'       => $hash,
            'comment'    => $comment,
        ]);
    }

    private function validated(Request $request, Document $document, ?DocumentControl $control = null): array
    {
        $validated = $request->validate([
            'doc_number'             => ['nullable', 'string', 'max:50', Rule::unique('document_controls', 'doc_number')->ignore($control?->id)],
            'owner_id'               => ['required', 'integer', 'exists:users,id'],
            'approver_id'            => ['required', 'integer', 'exists:users,id'],
            'classification'         => ['required', Rule::in(DocumentControl::CLASSIFICATIONS)],
            'review_interval_months' => ['required', 'integer', 'min:1', 'max:60'],
            'ack_required'           => ['sometimes', 'boolean'],
        ]);

        foreach (['owner_id', 'approver_id'] as $field) {
            $user = User::find($validated[$field]);
            if (! $user->isActive() || ! $user->canAccessWorkspace($document->workspace_id)) {
                throw ValidationException::withMessages([$field => 'This person must be active and able to open this page.']);
            }
        }

        // A blank number keeps the existing one, or is generated on creation.
        if (($validated['doc_number'] ?? null) === null && $control) {
            unset($validated['doc_number']);
        }

        return $validated;
    }
}
