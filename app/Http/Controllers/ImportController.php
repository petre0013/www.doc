<?php

namespace App\Http\Controllers;

use App\Jobs\ImportDocumentJob;
use App\Models\ConversionJob;
use App\Models\Document;
use App\Models\Workspace;
use App\Support\ImportTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'file'      => ['required', 'file', 'max:51200', 'mimes:docx,pdf'],
            'title'     => ['nullable', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('documents', 'id')->where('workspace_id', $workspace->id)
            ],
            // Import straight into a folder, mirroring documents.store. Folder
            // membership is top-level only, so it is mutually exclusive with
            // parent_id (below) — same invariant refile() enforces.
            'folder_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('document_folders', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $parentId = $data['parent_id'] ?? null;
        $folderId = $data['folder_id'] ?? null;

        if ($folderId !== null && $parentId !== null) {
            throw ValidationException::withMessages([
                'folder_id' => 'Only a top-level page can be filed in a folder.',
            ]);
        }

        $file   = $request->file('file');
        $format = strtolower($file->getClientOriginalExtension());

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = ImportTitle::placeholder($file->getClientOriginalName());
        }

        $document = Document::make([
            'title'          => $title,
            'workspace_id'   => $workspace->id,
            'parent_id'      => $parentId,
            'folder_id'      => $folderId,
            // Same slot a hand-made page gets — the placeholder is already in the
            // tree while it converts, so it has to land where the user expects.
            'position'       => Document::topPosition($workspace->id, $parentId, $folderId),
            'content'        => ['type' => 'doc', 'content' => []],
            'created_by_id'  => Auth::id(),
            'updated_by_id'  => Auth::id(),
        ]);

        // This empty row only holds the page's place in the tree while the queue
        // converts the file — importing is ONE user action, audited once when the
        // content lands (ImportDocumentJob), not here under the placeholder title.
        $document->importPlaceholder = true;
        $document->save();

        $uploadPath = $file->store("imports/{$format}", 'local');

        $job = ConversionJob::create([
            'document_id' => $document->id,
            'direction'   => 'import',
            'format'      => $format,
            // Carried to the job for the document.created audit context — the
            // stored path is randomised, so this is the only place the name the
            // user uploaded survives.
            'source_name' => $file->getClientOriginalName(),
            'status'      => 'pending',
            'result_path' => $uploadPath,
            // The queue worker runs unauthenticated — carry the importer's id
            // so assets extracted from the file are attributed to them, and the
            // address they uploaded from so the audit event isn't IP-less.
            'created_by_id' => Auth::id(),
            'ip'            => $request->ip(),
        ]);

        ImportDocumentJob::dispatch($job->id);

        return response()->json([
            'job_id'      => $job->id,
            'document_id' => $document->id,
        ], 202);
    }

    public function show(ConversionJob $job): JsonResponse
    {
        $this->authorize('view', $job);

        return response()->json([
            'status'      => $job->status,
            'error'       => $job->error,
            'document_id' => $job->document_id,
        ]);
    }

}
