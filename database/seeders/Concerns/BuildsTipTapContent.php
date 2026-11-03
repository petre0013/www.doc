<?php

namespace Database\Seeders\Concerns;

/**
 * The seeders' compact content DSL → TipTap JSON.
 *
 * Seeded pages are authored as arrays of plain strings (paragraphs) and small
 * block specs (`['type' => 'heading', …]`); this trait turns them into the
 * canonical `documents.content` JSON. Kept in ONE place so every seeder emits
 * the same node shapes the editor and RenderDocument agree on — a second,
 * drifting copy is exactly how schema-parity bugs get seeded in.
 */
trait BuildsTipTapContent
{
    protected function buildContent(array $items): array
    {
        $nodes = [];
        foreach ($items as $item) {
            $nodes[] = is_string($item)
                ? $this->paragraph($item)
                : $this->block($item);
        }
        return ['type' => 'doc', 'content' => $nodes];
    }

    protected function block(array $item): array
    {
        return match ($item['type']) {
            'heading'       => $this->heading($item['level'], $item['text'], $item['align'] ?? null),
            'paragraph'     => $this->richParagraph($item),
            'image'         => $this->image($item['src'], $item['alt'] ?? null, $item['width'] ?? null, $item['align'] ?? 'left'),
            'bulletList'    => $this->list('bulletList', $item['items']),
            'orderedList'   => $this->list('orderedList', $item['items']),
            'codeBlock'     => $this->codeBlock($item['language'] ?? null, $item['code']),
            'blockquote'    => $this->blockquote($item['text']),
            'table'         => $this->table($item['rows']),
            'diagram'       => $this->diagram($item['name'], $item['nodes'], $item['edges'] ?? [], $item['settings'] ?? []),
            'horizontalRule' => ['type' => 'horizontalRule'],
            default         => $this->paragraph((string) ($item['text'] ?? '')),
        };
    }

    protected function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => $this->inline($text)];
    }

    protected function heading(int $level, string $text, ?string $align = null): array
    {
        $attrs = ['level' => $level];
        if ($align) {
            $attrs['textAlign'] = $align;
        }
        return [
            'type'    => 'heading',
            'attrs'   => $attrs,
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    protected function image(string $src, ?string $alt, ?int $width = null, string $align = 'left'): array
    {
        return [
            'type'  => 'image',
            'attrs' => ['src' => $src, 'alt' => $alt, 'title' => null, 'width' => $width, 'align' => $align],
        ];
    }

    /**
     * Build a networkDiagram node from a compact spec. `imageSrc` is left null —
     * the derived PNG (used by exports/search) is generated when the diagram is
     * first opened and saved; the read view renders the live graph regardless.
     *
     * Node spec:  ['id','label','kind','color','x','y', 'w'?,'h'?, 'parent'?]
     *             ['id','group'=>true,'label','color','x','y','w','h']  (a zone)
     * Edge spec:  ['from','to', 'label'?,'routing'?,'arrows'?,'lineStyle'?,'color'?,'fromSide'?,'toSide'?]
     */
    protected function diagram(string $name, array $nodes, array $edges = [], array $settings = []): array
    {
        // Zones (groups) must precede their children in the node array.
        $ordered = array_merge(
            array_filter($nodes, fn ($n) => $n['group'] ?? false),
            array_filter($nodes, fn ($n) => ! ($n['group'] ?? false)),
        );

        $graphNodes = [];
        foreach ($ordered as $n) {
            if ($n['group'] ?? false) {
                $graphNodes[] = [
                    'id'       => $n['id'],
                    'type'     => 'group',
                    'position' => ['x' => $n['x'], 'y' => $n['y']],
                    'width'    => $n['w'] ?? 240,
                    'height'   => $n['h'] ?? 150,
                    'data'     => ['label' => $n['label'] ?? 'Zone', 'color' => $n['color'] ?? 'sage'],
                ];
                continue;
            }
            $node = [
                'id'       => $n['id'],
                'type'     => 'labeled',
                'position' => ['x' => $n['x'], 'y' => $n['y']],
                'data'     => [
                    'label' => $n['label'] ?? 'Node',
                    'kind'  => $n['kind'] ?? 'generic',
                    'color' => $n['color'] ?? 'default',
                ],
            ];
            if (isset($n['parent'])) $node['parentId'] = $n['parent'];
            if (isset($n['w']))      $node['width']    = $n['w'];
            if (isset($n['h']))      $node['height']   = $n['h'];
            $graphNodes[] = $node;
        }

        $graphEdges = array_map(fn ($e) => [
            'id'           => 'e-' . $e['from'] . '-' . $e['to'],
            'source'       => $e['from'],
            'target'       => $e['to'],
            'sourceHandle' => $e['fromSide'] ?? 'bottom',
            'targetHandle' => $e['toSide'] ?? 'top',
            'data'         => [
                'label'     => $e['label'] ?? '',
                'lineStyle' => $e['lineStyle'] ?? 'solid',
                'arrows'    => $e['arrows'] ?? 'end',
                'routing'   => $e['routing'] ?? 'curved',
                'color'     => $e['color'] ?? '#8E938E',
            ],
        ], $edges);

        $graph = [
            'nodes'    => array_values($graphNodes),
            'edges'    => array_values($graphEdges),
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ];
        if ($settings) {
            $graph['settings'] = $settings;
        }

        return [
            'type'  => 'networkDiagram',
            'attrs' => [
                'graph'    => $graph,
                'name'     => $name,
                'imageSrc' => null,
                'width'    => null,
                'align'    => 'left',
            ],
        ];
    }

    protected function list(string $type, array $items): array
    {
        $attrs = $type === 'orderedList' ? ['attrs' => ['start' => 1]] : [];
        return array_merge(
            ['type' => $type],
            $attrs,
            ['content' => array_map(fn ($item) => $this->listItem($item), $items)],
        );
    }

    /**
     * A list item from a plain string or a spec carrying a nested list:
     * ['text' => '…', 'sublist' => ['type' => 'bulletList'|'orderedList', 'items' => [...]]]
     */
    protected function listItem(string|array $item): array
    {
        $text    = is_string($item) ? $item : ($item['text'] ?? '');
        $content = [['type' => 'paragraph', 'content' => $this->inline($text)]];

        if (is_array($item) && isset($item['sublist'])) {
            $content[] = $this->list($item['sublist']['type'], $item['sublist']['items']);
        }

        return ['type' => 'listItem', 'content' => $content];
    }

    protected function codeBlock(?string $language, string $code): array
    {
        return [
            'type'    => 'codeBlock',
            'attrs'   => ['language' => $language],
            'content' => [['type' => 'text', 'text' => $code]],
        ];
    }

    protected function blockquote(string $text): array
    {
        return [
            'type'    => 'blockquote',
            'content' => [['type' => 'paragraph', 'content' => $this->inline($text)]],
        ];
    }

    /**
     * A paragraph built from styled spans, with optional text alignment:
     * ['type' => 'paragraph', 'align' => 'center'|'right'|'justify', 'spans' => [...]]
     *
     * Each span is a plain string (parsed for [[links]] / `code`) or a spec:
     *   ['text' => '…', 'bold'?, 'italic'?, 'underline'?, 'strike'?, 'code'? => true,
     *    'link'? => url, 'color'? => '#hex', 'highlight'? => '#hex']
     *   ['wikiLink' => 'Page Title']      — an inline wiki-link
     *   ['break' => true]                 — a hard line break
     */
    protected function richParagraph(array $item): array
    {
        $content = [];
        foreach ($item['spans'] ?? [] as $span) {
            if (is_string($span)) {
                $content = array_merge($content, $this->inline($span));
            } elseif ($span['break'] ?? false) {
                $content[] = ['type' => 'hardBreak'];
            } elseif (isset($span['wikiLink'])) {
                $content[] = ['type' => 'wikiLink', 'attrs' => ['title' => $span['wikiLink']]];
            } else {
                $content[] = $this->span($span);
            }
        }

        $paragraph = ['type' => 'paragraph', 'content' => $content];
        if (! empty($item['align'])) {
            $paragraph['attrs'] = ['textAlign' => $item['align']];
        }
        return $paragraph;
    }

    /** A single text node carrying any combination of marks. */
    protected function span(array $span): array
    {
        $marks = [];
        if ($span['bold'] ?? false)      $marks[] = ['type' => 'bold'];
        if ($span['italic'] ?? false)    $marks[] = ['type' => 'italic'];
        if ($span['underline'] ?? false) $marks[] = ['type' => 'underline'];
        if ($span['strike'] ?? false)    $marks[] = ['type' => 'strike'];
        if ($span['code'] ?? false)      $marks[] = ['type' => 'code'];
        if (! empty($span['link'])) {
            $marks[] = ['type' => 'link', 'attrs' => [
                'href'   => $span['link'],
                'target' => '_blank',
                'rel'    => 'noopener noreferrer nofollow',
                'class'  => null,
            ]];
        }
        if (! empty($span['color']))     $marks[] = ['type' => 'textStyle', 'attrs' => ['color' => $span['color']]];
        if (! empty($span['highlight'])) $marks[] = ['type' => 'highlight', 'attrs' => ['color' => $span['highlight']]];

        $node = ['type' => 'text', 'text' => $span['text'] ?? ''];
        if ($marks) {
            $node['marks'] = $marks;
        }
        return $node;
    }

    /**
     * A table from a row-major array; the FIRST row becomes header cells:
     * ['type' => 'table', 'rows' => [ ['H1','H2'], ['a','b'], … ]]
     */
    protected function table(array $rows): array
    {
        $content = [];
        foreach ($rows as $i => $row) {
            $cellType = $i === 0 ? 'tableHeader' : 'tableCell';
            $cells = array_map(fn ($cell) => [
                'type'    => $cellType,
                'attrs'   => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                'content' => [['type' => 'paragraph', 'content' => $this->inline((string) $cell)]],
            ], $row);
            $content[] = ['type' => 'tableRow', 'content' => $cells];
        }
        return ['type' => 'table', 'content' => $content];
    }

    protected function inline(string $text): array
    {
        // Parse [[wiki-links]] and `inline code` within a string
        $parts = preg_split('/(\[\[[^\[\]]+\]\]|`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $nodes = [];
        foreach ($parts as $part) {
            if (preg_match('/^\[\[([^\[\]]+)\]\]$/', $part, $m)) {
                $nodes[] = ['type' => 'wikiLink', 'attrs' => ['title' => trim($m[1])]];
            } elseif (preg_match('/^`([^`]+)`$/', $part, $m)) {
                $nodes[] = ['type' => 'text', 'text' => $m[1], 'marks' => [['type' => 'code']]];
            } elseif ($part !== '') {
                $nodes[] = ['type' => 'text', 'text' => $part];
            }
        }
        // An empty paragraph is `content: []` — never a `text: ''` node, which
        // ProseMirror rejects (text must be a non-empty string) and which would
        // blank the page in the editor.
        return $nodes;
    }
}
