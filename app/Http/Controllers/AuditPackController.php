<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentControl;
use App\Models\DocumentControlEvent;
use App\Models\RegisterItem;
use App\Services\Exporters\PdfExporter;
use App\Support\Audit;
use App\Support\Registers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Admin download for auditors: registers, sign-offs and approved PDFs with a hash manifest. */
class AuditPackController extends Controller
{
    public function download(Request $request, PdfExporter $pdf): BinaryFileResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        set_time_limit(300);

        $files = [];
        $controls = DocumentControl::with('document', 'owner:id,name', 'approver:id,name', 'approvedVersion')->whereHas('document')->get();

        $files['document-register.csv'] = $this->csv(
            ['id', 'title', 'status', 'classification', 'owner', 'approver', 'approved_at', 'next_review'],
            $controls->map(fn ($c) => [$c->doc_number, $c->document->title, $c->status, $c->classification, $c->owner?->name, $c->approver?->name, $c->approved_at?->toIso8601String(), $c->next_review_at?->toDateString()]),
        );

        $files['sign-offs.csv'] = $this->csv(
            ['document_id', 'doc_number', 'title', 'action', 'version_id', 'sha256', 'actor', 'actor_email', 'comment', 'at'],
            DocumentControlEvent::orderBy('id')->get()->map(fn ($e) => [$e->document_id, $e->doc_number, $e->document_title, $e->action, $e->document_version_id, $e->content_hash, $e->actor_name, $e->actor_email, $e->comment, $e->created_at->toIso8601String()]),
        );

        foreach (Registers::all() as $type => $config) {
            $keys = array_column($config['fields'], 'key');
            $files["registers/{$type}.csv"] = $this->csv(
                ['reference', 'title', 'status', 'owner', 'due', ...$keys],
                RegisterItem::where('type', $type)->with('owner:id,name')->orderBy('id')->get()->map(fn ($i) => [
                    $i->reference, $i->title, $i->status, $i->owner?->name, $i->due_at?->toDateString(),
                    ...array_map(fn ($key) => $i->data[$key] ?? null, $keys),
                ]),
            );
        }

        foreach ($controls->filter->approvedVersion as $control) {
            $files['approved/' . Str::slug($control->doc_number . '-' . $control->approvedVersion->title) . '.pdf'] = $this->approvedPdf($pdf, $control);
        }

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $request->user()->email,
            'files'        => array_map(fn (string $content) => hash('sha256', $content), $files),
        ];

        $path = tempnam(sys_get_temp_dir(), 'audit-pack');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        Audit::record('audit.pack_exported', null, ['files' => count($files)]);

        return response()->download($path, 'audit-pack-' . now()->format('Y-m-d') . '.zip')->deleteFileAfterSend();
    }

    /** In memory only: the page itself is never saved with the control block. */
    private function approvedPdf(PdfExporter $pdf, DocumentControl $control): string
    {
        $version = $control->approvedVersion;
        $line = fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $signOff = DocumentControlEvent::where('document_version_id', $version->id)->where('action', 'approved')->latest('id')->first();

        $document = (new Document)->forceFill(['slug' => Str::slug($control->doc_number), 'title' => $version->title]);
        $document->content = ['type' => 'doc', 'content' => [
            $line("Document ID: {$control->doc_number} · Classification: {$control->classification}"),
            $line('Owner: ' . ($control->owner?->name ?? 'none') . ' · Approved by: ' . ($signOff?->actor_name ?? 'unknown') . ' on ' . ($signOff?->created_at->toDateString() ?? 'unknown')),
            $line('Next review: ' . ($control->next_review_at?->toDateString() ?? 'none')),
            $line('Version SHA-256: ' . ($signOff?->content_hash ?? 'unknown')),
            ['type' => 'horizontalRule'],
            ...($version->content['content'] ?? []),
        ]];

        $stored = $pdf->export($document);
        $content = Storage::disk('local')->get($stored);
        Storage::disk('local')->delete($stored);

        return $content;
    }

    private function csv(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v, $row));
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
