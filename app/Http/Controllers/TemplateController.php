<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Template;
use App\Support\Audit;
use App\Support\TipTap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Template::class);

        return Inertia::render('Templates/Index', [
            'templates' => Template::with('creator:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'created_by_id', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Template::class);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $template = Template::create($validated + ['created_by_id' => Auth::id()]);

        Audit::record('template.created', $template, ['name' => $template->name]);

        // Straight into the editor — a template without content is no use yet.
        return redirect()->route('templates.edit', $template);
    }

    /** "Save as template": snapshot a page's current content as a new template. */
    public function storeFromDocument(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('create', Template::class);
        $this->authorize('view', $document);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $template = Template::create($validated + [
            'content'       => $document->content,
            'created_by_id' => Auth::id(),
        ]);

        Audit::record('template.created', $template, [
            'name'          => $template->name,
            'from_document' => $document->title,
        ]);

        return back()->with('success', "Saved \"{$template->name}\" as a template.");
    }

    public function edit(Template $template): Response
    {
        $this->authorize('update', $template);

        return Inertia::render('Templates/Edit', [
            'template' => $template->load('creator:id,name'),
        ]);
    }

    public function update(Request $request, Template $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'content'     => ['sometimes', 'nullable', 'array'],
        ]);

        // Same guarantee the document observer gives: stored TipTap JSON stays
        // valid for ProseMirror (no empty text nodes).
        if (is_array($validated['content'] ?? null)) {
            $validated['content'] = TipTap::normalize($validated['content']);
        }

        $template->update($validated);

        Audit::record('template.updated', $template, ['name' => $template->name]);

        // Stay in the editor — its unsaved-changes baseline resets on save and
        // the user may keep working; bouncing to the index threw them out.
        return back()->with('success', "Saved template \"{$template->name}\".");
    }

    public function destroy(Template $template): RedirectResponse
    {
        $this->authorize('delete', $template);

        $context = ['template_id' => $template->id, 'name' => $template->name];
        $template->delete();

        // Subject is gone (hard delete) — identity lives in context instead,
        // per the audit conventions for destroyed subjects.
        Audit::record('template.deleted', null, $context);

        return redirect()->route('templates.index')
            ->with('success', "Deleted template \"{$context['name']}\".");
    }
}
