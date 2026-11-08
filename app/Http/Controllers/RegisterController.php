<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\RegisterItem;
use App\Models\RegisterLink;
use App\Models\User;
use App\Support\Audit;
use App\Support\Registers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Registers hold sensitive records, so they are limited to editors and admins. */
class RegisterController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $this->gate($request);

        $items = RegisterItem::with('links')->get();
        $requirements = $items->where('type', 'requirement');

        return Inertia::render('Registers/Dashboard', [
            'registers' => collect(Registers::all())->map(fn ($config, $type) => [
                'type'    => $type,
                'label'   => $config['label'],
                'total'   => $items->where('type', $type)->count(),
                'open'    => $items->where('type', $type)->filter->isOpen()->count(),
                'overdue' => $items->where('type', $type)->filter->isOverdue()->count(),
            ])->values(),
            'requirements' => [
                'by_status'   => $requirements->countBy('status'),
                'no_evidence' => $requirements->where('status', 'implemented')
                    ->filter(fn ($item) => $item->links->whereNotNull('document_id')->isEmpty())
                    ->map(fn ($item) => $item->only('id', 'title', 'reference'))->values(),
            ],
            'incidents' => $items->where('type', 'incident')->filter->isOpen()->values()->map(fn ($item) => [
                'id' => $item->id, 'reference' => $item->reference, 'title' => $item->title,
                'deadlines' => Registers::incidentDeadlines($item),
            ]),
            'catalogueInstalled' => $requirements->isNotEmpty(),
        ]);
    }

    public function index(Request $request, string $type): Response
    {
        $this->gate($request);

        return Inertia::render('Registers/Index', [
            'type'   => $type,
            'config' => Registers::all()[$type],
            'users'  => $this->owners(),
            'items'  => RegisterItem::where('type', $type)->with('owner:id,name')->orderByDesc('id')->get()
                ->map(fn (RegisterItem $item) => $this->present($item)),
        ]);
    }

    public function show(Request $request, string $type, RegisterItem $item): Response
    {
        $this->gate($request);
        abort_unless($item->type === $type, 404);

        $user = $request->user();
        $item->load('owner:id,name', 'links.document:id,title,workspace_id', 'links.linkedItem:id,type,title,reference');

        return Inertia::render('Registers/Show', [
            'type'   => $type,
            'config' => Registers::all()[$type],
            'users'  => $this->owners(),
            'item'   => $this->present($item),
            'canAccept' => $type === 'risk' && $user->hasRole('admin'),
            // A link into a restricted workspace is dropped for non-members.
            'links' => $item->links->filter(fn ($link) => $link->linkedItem || ($link->document && $user->canAccessWorkspace($link->document->workspace_id)))->values()->map(fn ($link) => [
                'id'       => $link->id,
                'document' => $link->document?->only('id', 'title'),
                'item'     => $link->linkedItem?->only('id', 'type', 'title', 'reference'),
            ]),
            'documentOptions' => Document::accessibleBy($user)->orderBy('title')->get(['id', 'title']),
            'itemOptions'     => RegisterItem::whereKeyNot($item->id)->orderBy('type')->orderBy('title')->get(['id', 'type', 'title', 'reference']),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->gate($request);

        $item = DB::transaction(function () use ($request, $type) {
            $item = RegisterItem::create(['type' => $type] + $this->validated($request, $type));
            $this->assignReference($item);

            return $item;
        });

        Audit::record("register.created", null, $this->context($item));

        return redirect()->route('registers.show', [$type, $item]);
    }

    public function update(Request $request, string $type, RegisterItem $item): RedirectResponse
    {
        $this->gate($request);
        abort_unless($item->type === $type, 404);

        $before = $item->only('title', 'status', 'owner_id', 'data');
        $before['due_at'] = $item->due_at?->toDateString();
        $validated = $this->validated($request, $type);
        $item->update($validated);

        $changed = array_keys(array_filter(
            ['title' => 0, 'status' => 0, 'owner_id' => 0, 'due_at' => 0],
            fn ($_, $key) => ($before[$key] ?? null) != ($validated[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
        foreach ($validated['data'] as $key => $value) {
            if (($before['data'][$key] ?? null) != $value) {
                $changed[] = $key;
            }
        }

        if ($changed) {
            Audit::record('register.updated', null, $this->context($item) + ['changed' => $changed, 'status' => $item->status]);
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, string $type, RegisterItem $item): RedirectResponse
    {
        $this->gate($request);
        abort_unless($request->user()->hasRole('admin'), 403);
        abort_unless($item->type === $type, 404);

        $item->delete();
        Audit::record('register.deleted', null, $this->context($item));

        return redirect()->route('registers.index', $type)->with('success', 'Deleted.');
    }

    public function installCatalogue(Request $request): RedirectResponse
    {
        $this->gate($request);

        $created = 0;
        DB::transaction(function () use (&$created) {
            foreach (Registers::catalogue() as [$source, $title]) {
                if (RegisterItem::where('type', 'requirement')->where('data->source', $source)->exists()) {
                    continue;
                }

                $this->assignReference(RegisterItem::create([
                    'type' => 'requirement', 'title' => $title, 'status' => 'not_started', 'data' => ['source' => $source],
                ]));
                $created++;
            }
        });

        Audit::record('register.catalogue_installed', null, ['created' => $created]);

        return back()->with('success', "Added {$created} requirements.");
    }

    /** Management accepts the residual risk: recorded against the signed-in admin. */
    public function acceptRisk(Request $request, RegisterItem $item): RedirectResponse
    {
        $this->gate($request);
        abort_unless($request->user()->hasRole('admin'), 403);
        abort_unless($item->type === 'risk', 404);

        $user = $request->user();
        $item->update(['status' => 'accepted', 'data' => array_merge($item->data ?? [], [
            'accepted_by' => $user->name, 'accepted_email' => $user->email, 'accepted_at' => now()->toIso8601String(),
        ])]);

        Audit::record('register.risk_accepted', null, $this->context($item) + Registers::computed($item));

        return back()->with('success', 'Residual risk accepted.');
    }

    public function link(Request $request, string $type, RegisterItem $item): RedirectResponse
    {
        $this->gate($request);
        abort_unless($item->type === $type, 404);

        $validated = $request->validate([
            'document_id'    => ['nullable', 'required_without:linked_item_id', 'integer', Rule::exists('documents', 'id')->whereNull('deleted_at')],
            'linked_item_id' => ['nullable', 'required_without:document_id', 'integer', Rule::exists('register_items', 'id')->whereNull('deleted_at')],
        ]);

        if (! empty($validated['document_id'])) {
            $this->authorize('view', Document::findOrFail($validated['document_id']));
        }

        $item->links()->firstOrCreate(array_filter($validated));
        Audit::record('register.linked', null, $this->context($item) + array_filter($validated));

        return back();
    }

    public function unlink(Request $request, string $type, RegisterItem $item, RegisterLink $link): RedirectResponse
    {
        $this->gate($request);
        abort_unless($item->type === $type && $link->register_item_id === $item->id, 404);

        $context = $this->context($item) + array_filter($link->only('document_id', 'linked_item_id'));
        $link->delete();
        Audit::record('register.unlinked', null, $context);

        return back();
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'editor']), 403);
    }

    private function owners()
    {
        return User::role(['admin', 'editor'])->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']);
    }

    private function assignReference(RegisterItem $item): void
    {
        $item->reference = Registers::all()[$item->type]['prefix'] . '-' . str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
        $item->save();
    }

    private function context(RegisterItem $item): array
    {
        return ['type' => $item->type, 'reference' => $item->reference, 'title' => $item->title];
    }

    private function present(RegisterItem $item): array
    {
        return [
            'id'        => $item->id,
            'reference' => $item->reference,
            'title'     => $item->title,
            'status'    => $item->status,
            'owner_id'  => $item->owner_id,
            'owner'     => $item->owner?->name,
            'due_at'    => $item->due_at?->toDateString(),
            'overdue'   => $item->isOverdue(),
            'data'      => (object) ($item->data ?? []),
            'computed'  => Registers::computed($item),
        ];
    }

    private function validated(Request $request, string $type): array
    {
        $config = Registers::all()[$type];
        $rules = [
            'title'    => ['required', 'string', 'max:255'],
            'status'   => ['required', Rule::in($config['statuses'])],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at'   => ['nullable', 'date'],
        ];

        foreach ($config['fields'] as $field) {
            $rules["data.{$field['key']}"] = match ($field['type']) {
                'select'         => ['nullable', Rule::in($field['options'])],
                'date', 'datetime' => ['nullable', 'date'],
                default          => ['nullable', 'string', 'max:10000'],
            };
        }

        $validated = $request->validate($rules);
        $validated['data'] = array_merge(
            array_fill_keys(array_column($config['fields'], 'key'), null),
            $validated['data'] ?? [],
        );

        return $validated + ['owner_id' => null, 'due_at' => null];
    }
}
