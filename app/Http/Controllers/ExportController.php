<?php

namespace App\Http\Controllers;

use App\Jobs\ExportDocumentJob;
use App\Models\ConversionJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /** Start an export — creates a ConversionJob and dispatches the queue job. */
    public function store(Request $request, Document $document): JsonResponse
    {
        // Roles are global today, so 'view' can't fail for anyone who can
        // create a ConversionJob — but exporting IS reading the document, so
        // keep the boundary explicit for any future per-workspace scoping.
        $this->authorize('view', $document);
        $this->authorize('create', ConversionJob::class);

        $data = $request->validate([
            'format' => ['required', 'string', 'in:pdf,docx'],
        ]);

        $job = ConversionJob::create([
            'document_id' => $document->id,
            'direction'   => 'export',
            'format'      => $data['format'],
            'status'      => 'pending',
        ]);

        ExportDocumentJob::dispatch($job->id);

        return response()->json([
            'id'     => $job->id,
            'status' => $job->status,
        ], 202);
    }

    /**
     * Poll status OR serve the download.
     * ?download=1  → stream the file if done.
     * (default)    → return status JSON.
     */
    public function show(Request $request, Document $document, ConversionJob $job): JsonResponse|BinaryFileResponse
    {
        $this->authorize('view', $job);

        abort_if($job->document_id !== $document->id, 404);

        if ($request->boolean('download')) {
            abort_unless($job->status === 'done', 409, 'Export is not ready yet.');
            abort_unless($job->result_path && Storage::disk('local')->exists($job->result_path), 404);

            $mime = match ($job->format) {
                'pdf'  => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/octet-stream',
            };

            $ext      = $job->format;
            $basename = $document->slug . '.' . $ext;

            return response()->download(
                Storage::disk('local')->path($job->result_path),
                $basename,
                ['Content-Type' => $mime]
            );
        }

        return response()->json([
            'id'     => $job->id,
            'status' => $job->status,
            'error'  => $job->error,
        ]);
    }
}
