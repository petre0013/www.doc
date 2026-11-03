<?php

namespace App\Jobs;

use App\Models\ConversionJob;
use App\Services\Importers\DocxImporter;
use App\Services\Importers\PdfImporter;
use App\Support\ImportTitle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $conversionJobId) {}

    public function handle(DocxImporter $docx, PdfImporter $pdf): void
    {
        $job = ConversionJob::with('document')->findOrFail($this->conversionJobId);
        $job->update(['status' => 'processing']);

        try {
            $path = $job->result_path; // stores the temp upload path

            $result = match ($job->format) {
                'docx' => $docx->import(Storage::disk('local')->path($path), $job->created_by_id),
                'pdf'  => $pdf->import(Storage::disk('local')->path($path), $job->created_by_id),
                default => throw new \InvalidArgumentException("Unknown format: {$job->format}"),
            };

            $document = $job->document;

            // Overwrite the title only while it's still the placeholder — a title
            // typed at upload wins. Word/PDF files often carry no title of their
            // own; then the filename-derived name stands, which is what the user
            // recognises, rather than a generic "Imported document".
            if (ImportTitle::isPlaceholder($document->title)) {
                $document->title = $result['title']
                    ?? ImportTitle::fromFilename((string) $job->source_name);
            }

            $document->content = $result['content'];

            // The page becomes real here, so this save carries the import's single
            // document.created event (see DocumentObserver::saved). The folder rides
            // along the same way a straight-into-a-folder create does.
            $document->importCompleted   = true;
            $document->sourceImportName  = $job->source_name;
            $document->sourceFolderName  = $document->folder?->name;
            // No request here — the address the upload came from rides the job.
            $document->auditIp           = $job->ip;
            $document->save();

            // Clean up the temp upload
            Storage::disk('local')->delete($path);

            $job->update(['status' => 'done', 'result_path' => null]);
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Final-failure hook — also covers deaths the catch above never sees
     * (timeout kills the worker process, lost job payload), which would
     * otherwise leave the row stuck in `processing` with the UI polling
     * forever. Guarded so a failure the catch already recorded isn't redone.
     */
    public function failed(?Throwable $e): void
    {
        $job = ConversionJob::with('document')->find($this->conversionJobId);
        if (! $job) {
            return;
        }

        if (in_array($job->status, ['pending', 'processing'], true)) {
            $job->update([
                'status' => 'failed',
                'error'  => $e?->getMessage() ?? 'The import was interrupted.',
            ]);
        }

        // The placeholder page created at upload never got content — trash it
        // (soft delete, recoverable) so a failed import doesn't leave an empty
        // "Importing …" page in the tree.
        $document = $job->document;
        if ($document && ImportTitle::isPlaceholder($document->title)
            && \App\Support\TipTap::isEmpty($document->content)) {
            $document->delete();
        }
    }
}
