<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\DiagramSvg;
use App\Support\DocumentDiff;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only comparison views: two versions of a page (or a version against the
 * current state), and page-against-page. Both modes assemble the same
 * DocumentDiff payload and render the same Inertia page. No writes, no audit
 * events — rule 7 covers mutations only.
 */
class CompareController extends Controller
{
    /**
     * GET documents/{document}/versions/compare?from={id|current}&to={id|current}
     * Defaults: from = the second-latest snapshot (falling back to the latest),
     * to = the current page — i.e. "what changed most recently".
     */
    public function versions(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);

        $versions = $document->versions()
            ->with('creator:id,name')
            ->latest('id')
            ->get(['id', 'document_id', 'title', 'created_by_id', 'created_at']);

        abort_if($versions->isEmpty(), 404);

        $from = $request->query('from') ?? (string) ($versions->skip(1)->first() ?? $versions->first())->id;
        $to   = $request->query('to') ?? 'current';

        [$leftMeta, $leftPayload]   = $this->resolveVersionSide($document, (string) $from);
        [$rightMeta, $rightPayload] = $this->resolveVersionSide($document, (string) $to);

        return $this->render(
            mode: 'versions',
            workspace: $document->workspace,
            document: $document,
            left: [$leftMeta, $leftPayload],
            right: [$rightMeta, $rightPayload],
            versions: $versions->map(fn (DocumentVersion $v) => [
                'id'         => $v->id,
                'title'      => $v->title,
                'created_at' => $v->created_at->toIso8601String(),
                'creator'    => $v->creator?->only('id', 'name'),
            ])->all(),
        );
    }

    /** GET documents/compare?left={docId}&right={docId} */
    public function documents(Request $request): Response
    {
        $validated = $request->validate([
            'left'  => ['required', 'integer'],
            'right' => ['required', 'integer'],
        ]);

        $left  = Document::findOrFail($validated['left']);
        $right = Document::findOrFail($validated['right']);

        $this->authorize('view', $left);
        $this->authorize('view', $right);

        return $this->render(
            mode: 'documents',
            workspace: $left->workspace,
            document: null,
            left: [$this->documentMeta($left), $this->documentPayload($left)],
            right: [$this->documentMeta($right), $this->documentPayload($right)],
            versions: [],
        );
    }

    /**
     * A version-mode side: a snapshot id or the literal 'current'. Anything
     * else — including a version belonging to another document — is a 404.
     *
     * @return array{0:array,1:array} [descriptor for the UI, payload for DocumentDiff]
     */
    private function resolveVersionSide(Document $document, string $param): array
    {
        if ($param === 'current') {
            return [
                [
                    'kind'       => 'current',
                    'id'         => null,
                    'title'      => $document->title,
                    'created_at' => $document->updated_at?->toIso8601String(),
                    'creator'    => $document->updater?->only('id', 'name'),
                ],
                $this->documentPayload($document),
            ];
        }

        abort_unless(ctype_digit($param), 404);

        $version = DocumentVersion::with('creator:id,name')->findOrFail((int) $param);
        abort_if($version->document_id !== $document->id, 404);

        return [
            [
                'kind'       => 'version',
                'id'         => $version->id,
                'title'      => $version->title,
                'created_at' => $version->created_at->toIso8601String(),
                'creator'    => $version->creator?->only('id', 'name'),
            ],
            ['title' => $version->title, 'content' => $version->content, 'tags' => $version->tags ?? []],
        ];
    }

    private function documentMeta(Document $document): array
    {
        return [
            'kind'       => 'document',
            'id'         => $document->id,
            'title'      => $document->title,
            'created_at' => $document->updated_at?->toIso8601String(),
            'creator'    => $document->updater?->only('id', 'name'),
        ];
    }

    private function documentPayload(Document $document): array
    {
        return [
            'title'   => $document->title,
            'content' => $document->content,
            'tags'    => $document->tags()->orderBy('name')->pluck('name')->all(),
        ];
    }

    private function render(string $mode, $workspace, ?Document $document, array $left, array $right, array $versions): Response
    {
        $diff = DocumentDiff::compare($left[1], $right[1]);

        // Render each diagram's merged overlay graph through the regular
        // DiagramSvg (unchanged) into a data URI, like the read view does.
        $diff['diagrams'] = array_map(function (array $entry) {
            $rendered = $entry['overlay_graph'] ? DiagramSvg::render($entry['overlay_graph']) : null;
            unset($entry['overlay_graph']);

            $entry['overlay'] = $rendered ? [
                'src'    => 'data:image/svg+xml;base64,' . base64_encode($rendered['svg']),
                'width'  => $rendered['width'],
                'height' => $rendered['height'],
            ] : null;

            return $entry;
        }, $diff['diagrams']);

        return Inertia::render('Documents/Versions/Compare', [
            'mode'      => $mode,
            'workspace' => $workspace?->only('id', 'name'),
            'document'  => $document?->only('id', 'title', 'workspace_id'),
            'left'      => $left[0],
            'right'     => $right[0],
            'versions'  => $versions,
            'diff'      => $diff,
        ]);
    }
}
