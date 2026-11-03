<?php

namespace App\Support;

use App\Services\RenderDocument;
use Caxy\HtmlDiff\HtmlDiffConfig;
use RuntimeException;

/**
 * Compares two document payloads `{title, content (TipTap JSON), tags}` and
 * returns one structured array the Compare page renders from. Read-only and
 * pure — no models, no rendering side effects.
 *
 * Sections:
 *  - title/tags: plain value diffs.
 *  - body: word-level <ins>/<del> HTML via php-htmldiff over RenderDocument
 *    output. Diagrams are STRIPPED from the JSON before rendering — their
 *    base64-SVG <img> figures would drown the text diff in attribute churn —
 *    and diffed separately below.
 *  - blocks: coarse added/removed/modified entries for images and tables
 *    (fine-grained table cell diffs already appear inline in the body pass).
 *  - diagrams: structural graph diff keyed by React Flow ids, plus a merged
 *    "overlay" graph whose node/edge `data.color` encodes the change status —
 *    rendered by the EXISTING DiagramSvg (raw hex colors are supported there).
 */
class DocumentDiff
{
    /**
     * Combined size cap for the two body HTML sides. php-htmldiff is roughly
     * CUBIC in document size, so this has to be far lower than it looks:
     * measured on one side, ~10% of paragraphs edited — 8 KB → 0.3s,
     * 21 KB → 3s, 42 KB → 23s, 63 KB → 76s. Since FPM's default
     * max_execution_time is 30s (and nginx gives up at 60s), anything much
     * past ~25 KB per side doesn't render a slow page — it renders an error.
     * Past the cap we skip the inline diff and the page falls back to
     * side-by-side, which is just two plain renders and always finishes.
     */
    private const MAX_DIFF_BYTES = 50_000;

    /**
     * Every node type the differ understands — mirrors the editor schema.
     * A node outside this list throws (fail loudly, like SchemaParityTest):
     * silently mis-diffing a new node type would be worse than a 500.
     */
    private const KNOWN_NODES = [
        'doc', 'paragraph', 'heading', 'text', 'hardBreak',
        'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock',
        'horizontalRule', 'image', 'wikiLink',
        'table', 'tableRow', 'tableHeader', 'tableCell',
        'networkDiagram',
        // v1.3 editor nodes. All three are text-bearing containers: their
        // content flows through the rendered-HTML body diff like lists and
        // quotes do, so no dedicated block strategy is needed.
        'taskList', 'taskItem', 'callout',
    ];

    // Overlay status colors (styleguide: accent/text, danger, warning).
    private const COLOR_ADDED    = '#4B6840';
    private const COLOR_REMOVED  = '#B5573E';
    private const COLOR_MODIFIED = '#C99650';

    /**
     * @param array{title:string,content:?array,tags:array} $left  the OLD side
     * @param array{title:string,content:?array,tags:array} $right the NEW side
     */
    public static function compare(array $left, array $right): array
    {
        $identical = $left['title'] === $right['title']
            && ($left['content'] ?? []) == ($right['content'] ?? [])
            && array_values($left['tags']) === array_values($right['tags']);

        return [
            'identical' => $identical,
            'title'     => self::titleDiff($left['title'], $right['title']),
            'tags'      => self::tagsDiff($left['tags'], $right['tags']),
            'body'      => self::bodyDiff($left['content'] ?? null, $right['content'] ?? null),
            'blocks'    => self::blocksDiff(
                self::collectBlocks($left['content'] ?? null),
                self::collectBlocks($right['content'] ?? null),
            ),
            'diagrams'  => self::diagramsDiff(
                self::collectDiagrams($left['content'] ?? null),
                self::collectDiagrams($right['content'] ?? null),
            ),
        ];
    }

    /**
     * Cheap per-save change summary for the version history list. Runs inside
     * DocumentObserver on EVERY content save, so no htmldiff here — a plain
     * word-multiset delta is O(n) and approximate in the right direction
     * (moved text counts ~0).
     */
    public static function summarize(array $left, array $right): array
    {
        $old = self::wordBag($left['content'] ?? null);
        $new = self::wordBag($right['content'] ?? null);

        $added = 0;
        foreach ($new as $word => $count) {
            $added += max(0, $count - ($old[$word] ?? 0));
        }
        $removed = 0;
        foreach ($old as $word => $count) {
            $removed += max(0, $count - ($new[$word] ?? 0));
        }

        $blocks = self::blocksDiff(
            self::collectBlocks($left['content'] ?? null),
            self::collectBlocks($right['content'] ?? null),
        );

        $diagramChanged = self::collectDiagrams($left['content'] ?? null)
                       != self::collectDiagrams($right['content'] ?? null);

        return [
            'words_added'     => $added,
            'words_removed'   => $removed,
            'blocks_added'    => count(array_filter($blocks, fn ($b) => $b['status'] === 'added')),
            'blocks_removed'  => count(array_filter($blocks, fn ($b) => $b['status'] === 'removed')),
            'diagram_changed' => $diagramChanged,
            // Content changed but nothing above registered it — a formatting-
            // only edit (colours, highlights). Without this the history row
            // would imply an empty edit.
            'formatting_changed' => $added === 0 && $removed === 0
                && $blocks === [] && ! $diagramChanged
                && ($left['content'] ?? []) != ($right['content'] ?? []),
        ];
    }

    // ── Title + tags ────────────────────────────────────────────────────────

    private static function titleDiff(string $old, string $new): array
    {
        return ['changed' => $old !== $new, 'old' => $old, 'new' => $new];
    }

    private static function tagsDiff(array $old, array $new): array
    {
        return [
            'added'   => array_values(array_diff($new, $old)),
            'removed' => array_values(array_diff($old, $new)),
        ];
    }

    // ── Body (prose) ────────────────────────────────────────────────────────

    private static function bodyDiff(?array $oldDoc, ?array $newDoc): array
    {
        $oldHtml = RenderDocument::toHtml(self::stripDiagrams($oldDoc));
        $newHtml = RenderDocument::toHtml(self::stripDiagrams($newDoc));

        if ($oldHtml === $newHtml) {
            return ['changed' => false, 'html' => $newHtml, 'skipped' => false,
                    'formatting_only' => false,
                    'leftHtml' => $oldHtml, 'rightHtml' => $newHtml];
        }

        if (strlen($oldHtml) + strlen($newHtml) > self::MAX_DIFF_BYTES) {
            return ['changed' => true, 'html' => '', 'skipped' => true,
                    'formatting_only' => false,
                    'leftHtml' => $oldHtml, 'rightHtml' => $newHtml];
        }

        // Purifier off: both sides are RenderDocument output (user text is
        // already escaped there) — same trust level as the content_html we
        // already serve. Table diffing stays on (cell-level detail for free).
        $config = HtmlDiffConfig::create()->setPurifierEnabled(false);

        // Diff lists INLINE, not with caxy's list differ: that differ matches
        // items by their "relevant text", which only descends into a tag
        // whitelist that lacks <p> — and TipTap always renders <li><p>…</p>,
        // so every item read as empty, nothing matched, and UNCHANGED list
        // items rendered as removed + re-added. Inline diffing handles list
        // items like any other prose (word-level, unchanged items untouched).
        $isolated = $config->getIsolatedDiffTags();
        unset($isolated['ol'], $isolated['ul'], $isolated['dl']);
        $config->setIsolatedDiffTags($isolated);

        // Defensive: table diffing round-trips cell HTML through DOMDocument;
        // on anything libxml considers malformed it warns AND recovers, but
        // Laravel's handler would promote the warning to an ErrorException —
        // collect quietly instead. (The bare-"&" case that used to throw here
        // is fixed at its source in Utf8TableDiff::diffCells.)
        $useErrors = libxml_use_internal_errors(true);
        try {
            $html = Utf8HtmlDiff::create($oldHtml, $newHtml, $config)->build();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }

        // Utf8TableDiff carries non-ASCII through libxml as numeric entities,
        // so table cells come back as e.g. &#128640; — normalize to the raw
        // characters. The convmap starts at 0x80: &amp;/&lt; escaping of user
        // text is never touched.
        $html = mb_decode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        return [
            'changed'   => true,
            'html'      => $html,
            'skipped'   => false,
            // htmldiff only marks text/tag changes; attribute-only edits (text
            // colour, highlight colour) yield a diff with zero markers. Flag
            // that so the UI can say so instead of showing an unmarked body.
            'formatting_only' => ! preg_match('/<(ins|del)\b/', $html),
            'leftHtml'  => $oldHtml,
            'rightHtml' => $newHtml,
        ];
    }

    /** Recursively drop networkDiagram nodes (diffed separately) — and guard. */
    private static function stripDiagrams(?array $node): array
    {
        if ($node === null || $node === []) {
            return ['type' => 'doc', 'content' => []];
        }

        self::guardKnownNode($node);

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_values(array_filter(
                array_map(
                    fn ($child) => is_array($child) ? self::stripDiagrams($child) : $child,
                    $node['content'],
                ),
                fn ($child) => ! is_array($child) || ($child['type'] ?? null) !== 'networkDiagram',
            ));
        }

        return $node;
    }

    private static function guardKnownNode(array $node): void
    {
        $type = $node['type'] ?? null;
        if ($type !== null && ! in_array($type, self::KNOWN_NODES, true)) {
            throw new RuntimeException(
                "DocumentDiff does not understand node type '{$type}' — update " .
                'KNOWN_NODES and the diff logic (see the add-editor-node skill).',
            );
        }
    }

    // ── Blocks (images + tables, coarse) ────────────────────────────────────

    /** @return array<int,array{type:string,label:string,fingerprint:string}> in document order */
    private static function collectBlocks(?array $doc): array
    {
        $blocks = [];
        self::walk($doc, function (array $node) use (&$blocks) {
            if (($node['type'] ?? null) === 'image') {
                $src = (string) ($node['attrs']['src'] ?? '');
                $blocks[] = [
                    'type'        => 'image',
                    'label'       => ($node['attrs']['alt'] ?? null) ?: (basename(parse_url($src, PHP_URL_PATH) ?: '') ?: 'Image'),
                    'fingerprint' => $src,
                ];
            }
            if (($node['type'] ?? null) === 'table') {
                $rows = count($node['content'] ?? []);
                $cols = $rows ? count($node['content'][0]['content'] ?? []) : 0;
                $blocks[] = [
                    'type'        => 'table',
                    'label'       => "Table ({$rows}×{$cols})",
                    'fingerprint' => sha1(TipTap::plainText($node) . "|{$rows}x{$cols}"),
                ];
            }
        });

        return $blocks;
    }

    /**
     * Pair blocks of the same type by document-order index: same index with a
     * differing fingerprint = modified; an index present on one side only =
     * added/removed. Deliberately coarse — the body diff carries the detail.
     */
    private static function blocksDiff(array $old, array $new): array
    {
        $out = [];
        foreach (['image', 'table'] as $type) {
            $olds = array_values(array_filter($old, fn ($b) => $b['type'] === $type));
            $news = array_values(array_filter($new, fn ($b) => $b['type'] === $type));

            $count = max(count($olds), count($news));
            for ($i = 0; $i < $count; $i++) {
                $a = $olds[$i] ?? null;
                $b = $news[$i] ?? null;
                if ($a && $b) {
                    if ($a['fingerprint'] !== $b['fingerprint']) {
                        $out[] = ['type' => $type, 'status' => 'modified', 'label' => $b['label']];
                    }
                } elseif ($b) {
                    $out[] = ['type' => $type, 'status' => 'added', 'label' => $b['label']];
                } elseif ($a) {
                    $out[] = ['type' => $type, 'status' => 'removed', 'label' => $a['label']];
                }
            }
        }

        return $out;
    }

    // ── Diagrams ────────────────────────────────────────────────────────────

    /** @return array<int,array{name:string,graph:?array}> in document order */
    private static function collectDiagrams(?array $doc): array
    {
        $diagrams = [];
        self::walk($doc, function (array $node) use (&$diagrams) {
            if (($node['type'] ?? null) === 'networkDiagram') {
                $diagrams[] = [
                    'name'  => trim((string) ($node['attrs']['name'] ?? '')),
                    'graph' => $node['attrs']['graph'] ?? null,
                ];
            }
        });

        return $diagrams;
    }

    /**
     * Pair diagrams whose non-empty name is unique on both sides; the rest
     * pair by residual document order. Leftovers are whole added/removed
     * entries (their overlay is the full graph tinted in the status color).
     */
    private static function diagramsDiff(array $old, array $new): array
    {
        $pairs = [];
        $usedOld = $usedNew = [];

        $namesOf = function (array $list): array {
            $names = array_count_values(array_filter(array_column($list, 'name')));

            return array_keys(array_filter($names, fn ($n) => $n === 1));
        };
        $uniqueBoth = array_intersect($namesOf($old), $namesOf($new));

        foreach ($uniqueBoth as $name) {
            $i = array_search($name, array_column($old, 'name'), true);
            $j = array_search($name, array_column($new, 'name'), true);
            $pairs[] = [$old[$i], $new[$j]];
            $usedOld[$i] = $usedNew[$j] = true;
        }

        $restOld = array_values(array_filter($old, fn ($k) => ! isset($usedOld[$k]), ARRAY_FILTER_USE_KEY));
        $restNew = array_values(array_filter($new, fn ($k) => ! isset($usedNew[$k]), ARRAY_FILTER_USE_KEY));

        $count = max(count($restOld), count($restNew));
        for ($i = 0; $i < $count; $i++) {
            $pairs[] = [$restOld[$i] ?? null, $restNew[$i] ?? null];
        }

        $out = [];
        foreach ($pairs as [$a, $b]) {
            if ($a && $b) {
                $diff = self::graphDiff($a['graph'], $b['graph']);
                $changes = $diff['changes'];

                // Diagrams pair by unique name first, so a pair with DIFFERING
                // names got here by residual document order — i.e. a rename.
                // Without this the graph itself is untouched and the whole
                // diagram reports as unchanged.
                if ($a['name'] !== $b['name']) {
                    array_unshift($changes, ['kind' => 'diagram-renamed',
                        'text' => 'Renamed diagram “' . ($a['name'] ?: 'Untitled diagram')
                            . '” → “' . ($b['name'] ?: 'Untitled diagram') . '”']);
                }

                $out[] = [
                    'name'          => $b['name'] ?: 'Untitled diagram',
                    'status'        => $changes ? 'modified' : 'unchanged',
                    'changes'       => $changes,
                    'repositioned'  => $diff['repositioned'],
                    'overlay_graph' => ($changes || $diff['repositioned'])
                        ? self::mergedGraph($a['graph'], $b['graph'], $diff)
                        : null,
                ];
            } elseif ($b) {
                $out[] = [
                    'name'          => $b['name'] ?: 'Untitled diagram',
                    'status'        => 'added',
                    'changes'       => [],
                    'repositioned'  => 0,
                    'overlay_graph' => self::tintGraph($b['graph'], self::COLOR_ADDED),
                ];
            } elseif ($a) {
                $out[] = [
                    'name'          => $a['name'] ?: 'Untitled diagram',
                    'status'        => 'removed',
                    'changes'       => [],
                    'repositioned'  => 0,
                    'overlay_graph' => self::tintGraph($a['graph'], self::COLOR_REMOVED),
                ];
            }
        }

        return $out;
    }

    /**
     * Structural diff of two React Flow graphs. Node/edge ids are stable, so
     * this is a keyed comparison. Position-only moves are BUCKETED into a
     * count — layout noise must not drown semantic changes.
     */
    private static function graphDiff(?array $old, ?array $new): array
    {
        $oldNodes = self::byId($old['nodes'] ?? []);
        $newNodes = self::byId($new['nodes'] ?? []);

        $changes = [];
        $repositioned = 0;
        $nodeStatus = [];

        foreach (array_diff_key($newNodes, $oldNodes) as $id => $node) {
            $changes[] = ['kind' => 'node-added', 'text' => 'Added “' . self::label($node) . '”'];
            $nodeStatus[$id] = 'added';
        }
        foreach (array_diff_key($oldNodes, $newNodes) as $id => $node) {
            $changes[] = ['kind' => 'node-removed', 'text' => 'Removed “' . self::label($node) . '”'];
            $nodeStatus[$id] = 'removed';
        }

        foreach (array_intersect_key($newNodes, $oldNodes) as $id => $node) {
            $was = $oldNodes[$id];
            $semantic = [];

            if (self::label($was) !== self::label($node)) {
                $semantic[] = ['kind' => 'node-renamed', 'text' => 'Renamed “' . self::label($was) . '” → “' . self::label($node) . '”'];
            }
            if (($was['data']['color'] ?? null) !== ($node['data']['color'] ?? null)) {
                $semantic[] = ['kind' => 'node-recolored', 'text' => 'Recolored “' . self::label($node) . '”'];
            }
            if (($was['data']['kind'] ?? null) !== ($node['data']['kind'] ?? null)) {
                $semantic[] = ['kind' => 'node-retyped', 'text' => '“' . self::label($node) . '” type changed'];
            }
            if (($was['width'] ?? null) !== ($node['width'] ?? null) || ($was['height'] ?? null) !== ($node['height'] ?? null)) {
                $semantic[] = ['kind' => 'node-resized', 'text' => '“' . self::label($node) . '” resized'];
            }
            if (($was['parentId'] ?? null) !== ($node['parentId'] ?? null)) {
                $semantic[] = ['kind' => 'node-reparented', 'text' => '“' . self::label($node) . '” moved into another group'];
            }
            // `data.props` is the node's structured key/value list (IPs, models,
            // …) — content, not layout, and rendered inside the node by
            // DiagramSvg. Skipping it made an IP edit read as "nothing changed".
            $semantic = array_merge($semantic, self::propsChanges($was, $node));

            if ($semantic) {
                $changes = array_merge($changes, $semantic);
                $nodeStatus[$id] = 'modified';
            } elseif (($was['position'] ?? null) != ($node['position'] ?? null)) {
                $repositioned++;
            }
        }

        $oldEdges = self::edgesByKey($old['edges'] ?? [], $oldNodes, $newNodes);
        $newEdges = self::edgesByKey($new['edges'] ?? [], $oldNodes, $newNodes);
        $edgeStatus = [];

        foreach (array_diff_key($newEdges, $oldEdges) as $key => $edge) {
            $changes[] = ['kind' => 'edge-added', 'text' => 'Added edge ' . self::edgeLabel($edge, $newNodes)];
            $edgeStatus[$key] = 'added';
        }
        foreach (array_diff_key($oldEdges, $newEdges) as $key => $edge) {
            $changes[] = ['kind' => 'edge-removed', 'text' => 'Removed edge ' . self::edgeLabel($edge, $oldNodes)];
            $edgeStatus[$key] = 'removed';
        }
        foreach (array_intersect_key($newEdges, $oldEdges) as $key => $edge) {
            $was = $oldEdges[$key];
            // Id-keyed matches can still change endpoints (a reroute).
            if (($was['source'] ?? null) !== ($edge['source'] ?? null) || ($was['target'] ?? null) !== ($edge['target'] ?? null)
                || ($was['sourceHandle'] ?? null) !== ($edge['sourceHandle'] ?? null) || ($was['targetHandle'] ?? null) !== ($edge['targetHandle'] ?? null)) {
                $changes[] = ['kind' => 'edge-rerouted', 'text' => 'Rerouted edge ' . self::edgeLabel($edge, $newNodes)];
                $edgeStatus[$key] = 'modified';
            } elseif (($was['data'] ?? []) != ($edge['data'] ?? [])) {
                $changes[] = ['kind' => 'edge-restyled', 'text' => 'Restyled edge ' . self::edgeLabel($edge, $newNodes)];
                $edgeStatus[$key] = 'modified';
            }
        }

        return [
            'changes'      => $changes,
            'repositioned' => $repositioned,
            'nodeStatus'   => $nodeStatus,
            'edgeStatus'   => $edgeStatus,
        ];
    }

    /**
     * Per-node property changes, paired by list position (props are an ordered
     * list, like the rest of this file's index pairing). A same-key value edit
     * reads as from→to; anything structural (key renamed, row added/removed,
     * or an entry that isn't a {key,value} pair at all) collapses into one
     * entry — the overlay tints the node either way.
     *
     * @return array<int,array{kind:string,text:string}>
     */
    private static function propsChanges(array $was, array $node): array
    {
        $old = array_values((array) ($was['data']['props'] ?? []));
        $new = array_values((array) ($node['data']['props'] ?? []));

        if ($old == $new) {
            return [];
        }

        $name = self::label($node);
        $collapsed = [['kind' => 'node-props-changed', 'text' => '“' . $name . '” properties changed']];
        $out = [];

        for ($i = 0; $i < max(count($old), count($new)); $i++) {
            $a = $old[$i] ?? null;
            $b = $new[$i] ?? null;

            // Legacy/hand-edited graphs carry prop entries that are NOT
            // {key,value} arrays (DiagramSvg::normalizeNode skips them the same
            // way). Reading ['key'] off a string yields null on both sides, so
            // the per-index compare below would call them equal and report
            // nothing — the exact silence this method exists to end. We already
            // know the lists differ, so say so and stop.
            if (($a !== null && ! is_array($a)) || ($b !== null && ! is_array($b))) {
                return $collapsed;
            }

            if ($a && $b && ($a['key'] ?? '') === ($b['key'] ?? '')) {
                if (($a['value'] ?? '') !== ($b['value'] ?? '')) {
                    $key = trim((string) ($b['key'] ?? ''));
                    $out[] = ['kind' => 'node-prop-changed', 'text' => '“' . $name . '” ' . ($key !== '' ? $key . ': ' : '')
                        . '“' . ($a['value'] ?? '') . '” → “' . ($b['value'] ?? '') . '”'];
                }

                continue;
            }

            // Keys no longer line up — report once and stop guessing.
            return array_merge($out, $collapsed);
        }

        return $out;
    }

    /**
     * One graph carrying both versions, for the visual overlay: the NEW graph
     * (new positions win) plus removed nodes/edges from the old graph at their
     * old positions, with `data.color` overridden per status. Rendered by the
     * regular DiagramSvg — raw hex colors and dashed edges are already
     * supported there, so the renderer needs no changes.
     */
    private static function mergedGraph(?array $old, ?array $new, array $diff): ?array
    {
        $nodes = [];
        foreach ($new['nodes'] ?? [] as $node) {
            $status = $diff['nodeStatus'][$node['id'] ?? ''] ?? null;
            if ($status === 'added') {
                $node['data']['color'] = self::COLOR_ADDED;
            } elseif ($status === 'modified') {
                $node['data']['color'] = self::COLOR_MODIFIED;
            }
            $nodes[] = $node;
        }
        foreach ($old['nodes'] ?? [] as $node) {
            if (($diff['nodeStatus'][$node['id'] ?? ''] ?? null) === 'removed') {
                $node['data']['color'] = self::COLOR_REMOVED;
                $nodes[] = $node;
            }
        }

        $edges = [];
        $oldNodes = self::byId($old['nodes'] ?? []);
        $newNodes = self::byId($new['nodes'] ?? []);
        foreach (self::edgesByKey($new['edges'] ?? [], $oldNodes, $newNodes) as $key => $edge) {
            $status = $diff['edgeStatus'][$key] ?? null;
            if ($status === 'added') {
                $edge['data']['color'] = self::COLOR_ADDED;
            } elseif ($status === 'modified') {
                $edge['data']['color'] = self::COLOR_MODIFIED;
            }
            $edges[] = $edge;
        }
        foreach (self::edgesByKey($old['edges'] ?? [], $oldNodes, $newNodes) as $key => $edge) {
            if (($diff['edgeStatus'][$key] ?? null) === 'removed') {
                $edge['data']['color'] = self::COLOR_REMOVED;
                $edge['data']['lineStyle'] = 'dashed'; // ghost — renderer supports dashes
                $edges[] = $edge;
            }
        }

        return $nodes ? ['nodes' => $nodes, 'edges' => $edges] : null;
    }

    /** Whole graph in one status color (fully added/removed diagrams). */
    private static function tintGraph(?array $graph, string $color): ?array
    {
        if (! is_array($graph) || empty($graph['nodes'])) {
            return null;
        }

        $graph['nodes'] = array_map(function ($node) use ($color) {
            $node['data']['color'] = $color;

            return $node;
        }, $graph['nodes']);
        $graph['edges'] = array_map(function ($edge) use ($color) {
            $edge['data']['color'] = $color;

            return $edge;
        }, $graph['edges'] ?? []);

        return $graph;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Depth-first visit of every node (with the unknown-type guard). */
    private static function walk(?array $node, callable $visit): void
    {
        if ($node === null || $node === []) {
            return;
        }

        self::guardKnownNode($node);
        $visit($node);

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                self::walk($child, $visit);
            }
        }
    }

    private static function byId(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (isset($node['id'])) {
                $out[$node['id']] = $node;
            }
        }

        return $out;
    }

    /**
     * Key edges by their own id when that id exists on BOTH graphs' edge sets
     * being compared; otherwise fall back to the endpoints composite. Since
     * both sides run through this with the same node maps, keys line up.
     */
    private static function edgesByKey(array $edges, array $oldNodes, array $newNodes): array
    {
        $out = [];
        foreach ($edges as $edge) {
            $key = $edge['id']
                ?? (($edge['source'] ?? '?') . '|' . ($edge['sourceHandle'] ?? '') . '|'
                  . ($edge['target'] ?? '?') . '|' . ($edge['targetHandle'] ?? ''));
            $out[$key] = $edge;
        }

        return $out;
    }

    private static function label(array $node): string
    {
        return (string) (($node['data']['label'] ?? null) ?: 'Node');
    }

    private static function edgeLabel(array $edge, array $nodes): string
    {
        $name = fn ($id) => isset($nodes[$id]) ? self::label($nodes[$id]) : (string) $id;

        return $name($edge['source'] ?? '?') . ' → ' . $name($edge['target'] ?? '?');
    }

    /** @return array<string,int> word multiset over the doc's plain text */
    private static function wordBag(?array $doc): array
    {
        $words = preg_split('/\s+/u', trim(TipTap::plainText($doc)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_count_values($words);
    }
}
