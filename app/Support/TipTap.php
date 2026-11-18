<?php

namespace App\Support;

/**
 * Small helpers for reading TipTap (ProseMirror) JSON documents.
 *
 * The canonical content format is structured JSON; everything else (plain text
 * for link extraction now, HTML/search later) is derived from it — never the
 * reverse.
 */
class TipTap
{
    public static function plainText(?array $doc): string
    {
        return trim(preg_replace('/\s+/', ' ', self::extractText($doc)));
    }

    private static function extractText(?array $doc): string
    {
        if (! $doc) {
            return '';
        }

        $text = '';

        if (($doc['type'] ?? '') === 'wikiLink' && isset($doc['attrs']['title'])) {
            $text .= '[[' . $doc['attrs']['title'] . ']]';
        } elseif (isset($doc['text']) && is_string($doc['text'])) {
            $text .= $doc['text'];
        }

        foreach ($doc['content'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= self::extractText($child);
            }
        }

        if (in_array($doc['type'] ?? '', ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock'])) {
            $text = ' ' . $text . ' ';
        }

        return $text;
    }

    /**
     * Strip nodes that would make the canonical JSON invalid for ProseMirror —
     * today, text nodes whose `text` isn't a non-empty string. One such node
     * makes the editor's Node.fromJSON throw and blanks the whole page (while
     * tiptap-php renders it, so the read view looks fine). Mirrors the client
     * sanitizer so stored content is clean no matter which path wrote it.
     */
    public static function normalize(?array $node): ?array
    {
        if (! is_array($node)) {
            return $node;
        }

        if (($node['type'] ?? null) === 'image' && is_string($node['attrs']['src'] ?? null)) {
            $node['attrs']['src'] = self::relativizeAssetUrl($node['attrs']['src']);
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $clean = [];
            foreach ($node['content'] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                if (($child['type'] ?? null) === 'text'
                    && (! isset($child['text']) || ! is_string($child['text']) || $child['text'] === '')) {
                    continue;
                }
                $clean[] = self::normalize($child);
            }
            $node['content'] = $clean;
        }

        return $node;
    }

    /**
     * Turn an absolute URL to one of OUR stored assets back into its
     * root-relative /storage/… form.
     *
     * Copying a page's content in the browser absolutizes every <img src>, so
     * pasting it back (same page, another page, another tab) carries
     * `https://<host>/storage/assets/…`. The paste pipeline can't rehost it —
     * the SSRF guard rightly refuses the app's own private address — so the
     * absolute URL used to be saved verbatim. It then breaks the moment the
     * instance is reached under another scheme/host/port (served over HTTP:
     * the browser dials :443 and gets ERR_SSL_UNRECOGNIZED_NAME_ALERT), and
     * exports try to fetch it instead of reading the disk.
     *
     * Rewritten only when the file actually exists on the public disk: asset
     * names are content hashes, so an existing file IS the same image no
     * matter which host the URL named. Anything else is left untouched.
     */
    public static function relativizeAssetUrl(string $src): string
    {
        if (! preg_match('#^https?://[^/?\#]+(/storage/[^?\#]+)#i', $src, $m)) {
            return $src;
        }

        $relative = rawurldecode(substr($m[1], strlen('/storage/')));
        if ($relative === '' || str_contains($relative, '..')) {
            return $src;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->exists($relative)
            ? $m[1]
            : $src;
    }

    /**
     * Whether a document has no meaningful content — i.e. it's null, has no
     * child nodes, or contains only empty paragraphs. Any other block (heading,
     * image, table, list, …) or any text counts as content.
     */
    public static function isEmpty(?array $doc): bool
    {
        if (! $doc || empty($doc['content'])) {
            return true;
        }

        foreach ($doc['content'] as $node) {
            if (($node['type'] ?? null) !== 'paragraph') {
                return false;
            }

            if (! empty($node['content'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract the targets referenced by [[Wiki-link]] syntax, de-duplicated and
     * order-preserved. Returns array of ['title' => string, 'target_id' => int|null].
     *
     * Handles two storage formats:
     *  - Legacy: [[Title]] as literal text inside paragraph text nodes.
     *  - Phase 2+: {type: "wikiLink", attrs: {title: "...", target_id: 123}} custom nodes.
     *
     * @return list<array>
     */
    public static function wikiLinkTargets(?array $doc): array
    {
        $targets = [];

        // Collect from wikiLink nodes (Phase 2+ format)
        self::collectWikiLinkNodes($doc, $targets);

        // Collect from [[Title]] text patterns (legacy / plain-text format)
        preg_match_all('/\[\[([^\[\]]+)\]\]/', self::plainText($doc), $matches);
        foreach ($matches[1] ?? [] as $t) {
            $title = trim($t);
            if ($title !== '') {
                // Only add if we don't already have a target for this title
                $exists = false;
                foreach ($targets as $tgt) {
                    if ($tgt['title'] === $title) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $targets[] = ['title' => $title, 'target_id' => null];
                }
            }
        }

        return $targets;
    }

    /**
     * Extract a short context snippet (≤ 200 chars) of plain text surrounding
     * the [[Wiki-link]] with the given title within the document.
     */
    public static function contextAround(?array $doc, string $title): string
    {
        if (! $doc) {
            return '';
        }

        foreach ($doc['content'] ?? [] as $topNode) {
            if (! self::nodeContainsWikiLink($topNode, $title)) {
                continue;
            }

            $blockText = self::plainText($topNode);
            $needle    = "[[{$title}]]";
            $pos       = mb_strpos($blockText, $needle);

            if ($pos === false) {
                return mb_substr($blockText, 0, 200);
            }

            $start   = max(0, $pos - 80);
            $snippet = mb_substr($blockText, $start, 200);

            return ($start > 0 ? '…' : '') . trim($snippet) . (mb_strlen($blockText) > $start + 200 ? '…' : '');
        }

        return '';
    }

    private static function nodeContainsWikiLink(?array $node, string $title): bool
    {
        if (! $node) {
            return false;
        }

        if (($node['type'] ?? '') === 'wikiLink' && trim($node['attrs']['title'] ?? '') === $title) {
            return true;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && self::nodeContainsWikiLink($child, $title)) {
                return true;
            }
        }

        return false;
    }

    /** Recursively collect targets from wikiLink node atoms. */
    private static function collectWikiLinkNodes(?array $node, array &$targets): void
    {
        if (! $node) {
            return;
        }

        if (($node['type'] ?? '') === 'wikiLink') {
            $title = trim($node['attrs']['title'] ?? '');
            $target_id = $node['attrs']['target_id'] ?? null;
            if ($title !== '') {
                // Check if we already have this target
                $exists = false;
                foreach ($targets as $tgt) {
                    if ($tgt['title'] === $title && $tgt['target_id'] === $target_id) {
                        $exists = true; break;
                    }
                }
                if (!$exists) {
                    $targets[] = ['title' => $title, 'target_id' => $target_id];
                }
            }
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                self::collectWikiLinkNodes($child, $targets);
            }
        }
    }
}
