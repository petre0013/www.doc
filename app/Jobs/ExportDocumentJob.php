<?php

namespace App\Jobs;

use App\Models\ConversionJob;
use App\Services\Exporters\DocxExporter;
use App\Services\Exporters\PdfExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $conversionJobId) {}

    public function handle(): void
    {
        $job = ConversionJob::findOrFail($this->conversionJobId);
        $job->update(['status' => 'processing']);

        try {
            $path = match ($job->format) {
                'pdf'  => app(PdfExporter::class)->export($job->document),
                'docx' => app(DocxExporter::class)->export($job->document),
                default => throw new \InvalidArgumentException("Unknown format: {$job->format}"),
            };

            $job->update(['status' => 'done', 'result_path' => $path]);
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Final-failure hook — covers deaths the catch never sees (timeout kills
     * the worker, lost payload) so the row can't stay stuck in `processing`.
     */
    public function failed(?Throwable $e): void
    {
        $job = ConversionJob::find($this->conversionJobId);

        if ($job && in_array($job->status, ['pending', 'processing'], true)) {
            $job->update([
                'status' => 'failed',
                'error'  => $e?->getMessage() ?? 'The export was interrupted.',
            ]);
        }
    }
}
