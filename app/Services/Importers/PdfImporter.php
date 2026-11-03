<?php

namespace App\Services\Importers;

use App\Contracts\ImporterContract;
use Smalot\PdfParser\Parser;

class PdfImporter implements ImporterContract
{
    // A bullet glyph is unambiguous — a single "• item" line is a list. ASCII
    // markers (-, *, –) also open sentences/dialogue, so they only count in
    // runs of two or more.
    private const GLYPH_BULLET_RE = '/^[\x{2022}\x{25E6}\x{25AA}\x{2023}\x{00B7}]\s+(.+)$/u';
    private const ASCII_BULLET_RE = '/^[-*\x{2013}]\s+(.+)$/u';

    // 1–3 digits keeps years ("2026 was…") out; the run rules below keep
    // number-leading prose ("5. May was rainy" as a lone line) out too.
    private const NUMBER_RE = '/^(\d{1,3})[.)]\s+(.+)$/u';

    public function import(string $filePath, ?int $uploadedById = null): array
    {
        $parser = new Parser();
        $pdf    = $parser->parseFile($filePath);

        // Best-effort title from metadata
        $details = $pdf->getDetails();
        $title   = trim((string) ($details['Title'] ?? ''));

        $lines = [];
        foreach ($pdf->getPages() as $page) {
            $text = trim($page->getText());
            if ($text === '') {
                continue;
            }
            foreach (explode("\n", $text) as $line) {
                $lines[] = trim($line);
            }
        }

        $nodes = $this->linesToNodes($lines);

        if (empty($nodes)) {
            $nodes = [['type' => 'paragraph', 'content' => []]];
        }

        return [
            // null = no title in the PDF metadata; see DocxImporter — the caller
            // falls back to the filename-derived name.
            'title'   => $title !== '' ? $title : null,
            'content' => ['type' => 'doc', 'content' => $nodes],
        ];
    }

    /**
     * Turn extracted text lines into blocks. PDFs carry no structural markup —
     * lists exist only as visible "•" / "1." prefixes — so this is deliberate
     * best-effort heuristics for FLAT lists (nesting depends on indentation the
     * text extraction doesn't preserve). Everything unmatched stays a
     * paragraph, and blank lines keep their visual-separation paragraphs.
     */
    private function linesToNodes(array $lines): array
    {
        $nodes = [];
        $count = count($lines);

        for ($i = 0; $i < $count;) {
            $line = $lines[$i];

            if ($line === '') {
                $nodes[] = ['type' => 'paragraph', 'content' => []];
                $i++;
                continue;
            }

            // Bullet run?
            $texts = [];
            $j     = $i;
            $glyph = false;
            while ($j < $count) {
                if (preg_match(self::GLYPH_BULLET_RE, $lines[$j], $m)) {
                    $glyph   = true;
                    $texts[] = $m[1];
                } elseif (preg_match(self::ASCII_BULLET_RE, $lines[$j], $m)) {
                    $texts[] = $m[1];
                } else {
                    break;
                }
                $j++;
            }
            if ($texts !== [] && ($glyph || count($texts) >= 2)) {
                $nodes[] = $this->list('bulletList', $texts);
                $i = $j;
                continue;
            }

            // Numbered run? Only a consecutive ascending sequence (or a lone
            // "1.") reads as a list — anything else is prose that happens to
            // start with a number.
            $texts = [];
            $j     = $i;
            $start = null;
            while ($j < $count && preg_match(self::NUMBER_RE, $lines[$j], $m)) {
                $n = (int) $m[1];
                if ($start === null) {
                    $start = $n;
                } elseif ($n !== $start + count($texts)) {
                    break;
                }
                $texts[] = $m[2];
                $j++;
            }
            if ($texts !== [] && (count($texts) >= 2 || $start === 1)) {
                $nodes[] = $this->list('orderedList', $texts, $start);
                $i = $j;
                continue;
            }

            $nodes[] = [
                'type'    => 'paragraph',
                'content' => [['type' => 'text', 'text' => $line]],
            ];
            $i++;
        }

        return $nodes;
    }

    /** @param list<string> $texts */
    private function list(string $type, array $texts, ?int $start = null): array
    {
        $node = [
            'type'    => $type,
            'content' => array_map(fn (string $t) => [
                'type'    => 'listItem',
                'content' => [[
                    'type'    => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $t]],
                ]],
            ], $texts),
        ];

        if ($type === 'orderedList' && $start !== null && $start !== 1) {
            $node['attrs'] = ['start' => $start];
        }

        return $node;
    }
}
