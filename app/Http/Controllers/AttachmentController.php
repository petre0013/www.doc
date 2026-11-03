<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentController extends Controller
{
    // Private disk — attachments are served through download() (forced
    // Content-Disposition: attachment), never a public URL.
    private const DISK = 'local';

    private const MAX_KB = 25 * 1024; // 25 MB

    /**
     * Upload a file and attach it to the page. Permission tracks the page:
     * being able to edit the document is what lets you add files to it.
     *
     * Returns 204 (no body): the editor stages attachment changes client-side and
     * commits them with fetch() as part of the page Save, so it needs a terse
     * success signal, not an Inertia redirect/re-render.
     */
    public function store(Request $request, Document $document): Response
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB],
            // Optional display name (used as the download filename). Only the base
            // name is honoured — the extension is always forced to the real file's
            // (see below), so it can't be changed. Falls back to the uploaded
            // file's own name when blank.
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $file    = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $ext     = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin');
        $path    = 'attachments/'.Str::ulid().'.'.$ext;

        // Keep the display name's extension pinned to the actual file. The user may
        // rename the base, but any extension they type is stripped and the real one
        // re-applied, so the download always opens correctly.
        $displayExt = $file->getClientOriginalExtension();
        $base       = pathinfo(trim($validated['name'] ?? ''), PATHINFO_FILENAME);
        if ($base === '') {
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        }
        $name = $displayExt !== '' ? "{$base}.{$displayExt}" : $base;

        Storage::disk(self::DISK)->put($path, $content);

        $document->attachments()->create([
            'disk'           => self::DISK,
            'path'           => $path,
            'original_name'  => $name,
            'mime'           => $file->getMimeType(),
            'size'           => strlen($content),
            'checksum'       => hash('sha256', $content),
            'uploaded_by_id' => Auth::id(),
            'position'       => (int) $document->attachments()->max('position') + 1,
        ]);

        return response()->noContent();
    }

    /** Stream an attachment as a download. Anyone who can view the page can download. */
    public function download(Document $document, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_unless($attachment->document_id === $document->id, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    /**
     * Rename an attachment's display name (the download filename). Only the base
     * is editable — the extension is re-pinned from the stored file, mirroring
     * store(), so a rename can never change what kind of file downloads. Like the
     * other attachment ops this is committed client-side as part of the page Save
     * and returns 204.
     */
    public function update(Request $request, Document $document, Attachment $attachment): Response
    {
        $this->authorize('update', $document);
        abort_unless($attachment->document_id === $document->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Real extension lives on the stored path (attachments/{ulid}.{ext}); the
        // typed extension is stripped and the real one re-applied.
        $ext  = pathinfo($attachment->path, PATHINFO_EXTENSION);
        $base = pathinfo(trim($validated['name']), PATHINFO_FILENAME);
        if ($base === '') {
            throw ValidationException::withMessages(['name' => 'Attachment name cannot be blank.']);
        }

        $attachment->update([
            'original_name' => $ext !== '' ? "{$base}.{$ext}" : $base,
        ]);

        return response()->noContent();
    }

    /** Remove an attachment from the page (and its binary, via the model event). */
    public function destroy(Document $document, Attachment $attachment): Response
    {
        $this->authorize('update', $document);
        abort_unless($attachment->document_id === $document->id, 404);

        $attachment->delete();

        return response()->noContent();
    }
}
