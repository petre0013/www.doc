<?php

namespace App\Services\Importers;

use App\Contracts\ImporterContract;
use DOMDocument;
use PhpOffice\PhpWord\Element\ListItem as ListItemElement;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table as TableElement;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\ListItem as ListItemStyle;
use PhpOffice\PhpWord\Style\Numbering;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Image;
use Tiptap\Nodes\Table;
use Tiptap\Nodes\TableCell;
use Tiptap\Nodes\TableHeader;
use Tiptap\Nodes\TableRow;

class DocxImporter implements ImporterContract
{
    // Private-use-area sentinels: survive PhpWord's escapeHTML untouched and
    // cannot collide with real document text.
    private const LIST_MARK_START = "\u{E000}";
    private const LIST_MARK_END   = "\u{E001}";
    private const LIST_MARK_RE    = '/\x{E000}(\d+):(ul|ol)\x{E001}/u';

    // OOXML numbering formats that read as an ordered list. Everything else
    // ('bullet', 'none', unknown) stays a bullet list.
    private const ORDERED_FORMATS = [
        'decimal', 'decimalZero', 'decimalEnclosedCircle', 'decimalEnclosedFullstop',
        'decimalEnclosedParen', 'upperRoman', 'lowerRoman', 'upperLetter', 'lowerLetter',
    ];

    public function __construct(private readonly AssetStore $assetStore) {}

    public function import(string $filePath, ?int $uploadedById = null): array
    {
        // PhpWord's reader checks numbering before heading style, so a *numbered*
        // heading (heading style + <w:numPr>, very common in technical docs) is
        // read as a list item and loses its heading level. Strip the numbering
        // from heading paragraphs first so they're recognised as headings.
        $this->unNumberHeadings($filePath);

        $phpWord = IOFactory::load($filePath, 'Word2007');

        // PhpWord's HTML writer flattens every list item to a bare <p> — the
        // model's depth and numbering type never reach the HTML. Tag each list
        // item's text with a sentinel carrying that structure now, so
        // rebuildLists() can reconstruct <ul>/<ol> nesting from the flat HTML.
        $this->tagListItems($phpWord);

        // Extract title from document properties before rendering HTML
        $props = $phpWord->getDocInfo();
        $title = trim((string) $props->getTitle());

        // PhpWord HTML writer → raw HTML string
        $writer = IOFactory::createWriter($phpWord, 'HTML');
        $html   = $writer->getContent();

        // Normalise inline styles + rehost embedded base64 images in one DOM pass.
        // The uploader rides the ConversionJob row (queue workers have no auth);
        // a null user stores the assets unattributed rather than pinning them on
        // an arbitrary account.
        $html = $this->normalizeHtml($html, $uploadedById ?? auth()->id());

        // Strip the full HTML envelope; tiptap-php only wants the body content
        $body = $this->extractBody($html);

        // Use the one canonical schema parser so we don't drop formatting (like
        // text alignment or colours) that the main schema supports.
        $doc = \App\Services\RenderDocument::fromHtml($body);

        return [
            // null = the file carries no title of its own; the caller keeps the
            // name it already derived from the filename rather than inventing one.
            'title'   => $title !== '' ? $title : null,
            'content' => $doc,
        ];
    }

    /**
     * Remove <w:numPr> from heading-styled paragraphs in word/document.xml, in
     * place. Numbered headings would otherwise be read as list items because
     * PhpWord's reader matches numbering before heading style. The auto-generated
     * number prefix is cosmetic and not part of the heading text, so dropping it
     * is lossless for our purposes. Real lists (no heading style) are untouched.
     */
    private function unNumberHeadings(string $filePath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return;
        }

        $patched = preg_replace_callback(
            '#<w:pPr\b[^>]*>.*?</w:pPr>#s',
            function (array $m): string {
                $pPr = $m[0];

                if (! preg_match('/<w:pStyle\b[^>]*w:val="Heading\d"/', $pPr)) {
                    return $pPr;
                }

                $pPr = preg_replace('#<w:numPr\b[^>]*>.*?</w:numPr>#s', '', $pPr);

                return preg_replace('#<w:numPr\b[^>]*/>#', '', $pPr);
            },
            $xml
        );

        if (is_string($patched) && $patched !== $xml) {
            $zip->addFromString('word/document.xml', $patched);
        }

        $zip->close();
    }

    /** Append a depth/type sentinel to every list item's text, recursively. */
    private function tagListItems(PhpWord $phpWord): void
    {
        foreach ($phpWord->getSections() as $section) {
            $this->tagListItemsIn($section);
        }
    }

    private function tagListItemsIn(object $container): void
    {
        if (! method_exists($container, 'getElements')) {
            return;
        }

        foreach ($container->getElements() as $el) {
            if ($el instanceof ListItemElement) {
                // Simple item: one text object we can extend in place.
                $text = $el->getTextObject();
                $text->setText($text->getText() . $this->listMarker($el->getDepth(), $el->getStyle()));
            } elseif ($el instanceof ListItemRun) {
                // Run container (what the Word2007 reader produces): append the
                // sentinel as a final run so formatting/link runs stay intact,
                // then demote to TextRun — the HTML Container writer inlines a
                // TextRun's runs into ONE <p>, but shreds a ListItemRun into
                // one <p> PER RUN (it matches the class name 'TextRun'
                // literally), which would scatter the item across paragraphs.
                $el->addText($this->listMarker($el->getDepth(), $el->getStyle()));
                $this->demoteToTextRun($container, $el);
            } elseif ($el instanceof TableElement) {
                foreach ($el->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->tagListItemsIn($cell);
                    }
                }
            } else {
                $this->tagListItemsIn($el);
            }
        }
    }

    /**
     * Swap a ListItemRun for a plain TextRun carrying the same child runs, in
     * place in its parent container. The list structure is already encoded in
     * the sentinel by the time this runs, and TextRun is the parent class —
     * only the class NAME changes as far as the HTML writer is concerned.
     * PhpWord offers no public elements accessor, hence the reflection;
     * the dependency is locked (composer.lock) and covered by import tests.
     */
    private function demoteToTextRun(object $parent, ListItemRun $run): void
    {
        $textRun = new \PhpOffice\PhpWord\Element\TextRun($run->getParagraphStyle());

        $elements = new \ReflectionProperty(\PhpOffice\PhpWord\Element\AbstractContainer::class, 'elements');
        $elements->setAccessible(true);
        $elements->setValue($textRun, $elements->getValue($run));

        $siblings = $elements->getValue($parent);
        $idx = array_search($run, $siblings, true);
        if ($idx !== false) {
            $siblings[$idx] = $textRun;
            $elements->setValue($parent, $siblings);
        }
    }

    /** `depth:tag` sentinel for one list item. */
    private function listMarker(int $depth, ?ListItemStyle $style): string
    {
        $tag = 'ul';

        if ($style !== null) {
            $numbering = $style->getNumStyle() ? Style::getStyle($style->getNumStyle()) : null;

            if ($numbering instanceof Numbering) {
                // Real Word numbering definition: the format at this item's
                // depth decides (a numbered list can nest bulleted levels and
                // vice versa).
                $format = null;
                foreach ($numbering->getLevels() as $level) {
                    if ((int) $level->getLevel() === $depth) {
                        $format = $level->getFormat();
                        break;
                    }
                }
                $tag = in_array($format, self::ORDERED_FORMATS, true) ? 'ol' : 'ul';
            } else {
                // Legacy/simple style (addListItem fixtures, older producers).
                $tag = $style->getListType() >= ListItemStyle::TYPE_NUMBER ? 'ol' : 'ul';
            }
        }

        return self::LIST_MARK_START . $depth . ':' . $tag . self::LIST_MARK_END;
    }

    /**
     * Rebuild <ul>/<ol> nesting from sentinel-tagged flat <p> runs. Groups of
     * consecutive tagged paragraphs (per parent, so table cells group
     * independently) become one list tree; depth increases nest inside the
     * previous item, and a type flip at the same depth starts a sibling list.
     */
    private function rebuildLists(DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);

        $tagged = [];
        foreach ($xpath->query('//p') as $p) {
            if (preg_match(self::LIST_MARK_RE, $p->textContent, $m)) {
                $tagged[] = ['p' => $p, 'depth' => (int) $m[1], 'tag' => $m[2]];
            }
        }

        $byNode = [];
        foreach ($tagged as $t) {
            $byNode[spl_object_id($t['p'])] = $t;
        }

        $consumed = new \SplObjectStorage();
        foreach ($tagged as $item) {
            if ($consumed->contains($item['p'])) {
                continue;
            }

            // Collect the run of consecutive tagged <p> siblings starting here
            // (whitespace-only text nodes between them don't break the run).
            $group = [$item];
            $node  = $item['p']->nextSibling;
            while ($node !== null) {
                if ($node instanceof \DOMText && trim($node->textContent) === '') {
                    $node = $node->nextSibling;
                    continue;
                }
                $t = $byNode[spl_object_id($node)] ?? null;
                if ($t === null) {
                    break;
                }
                $group[] = $t;
                $node = $node->nextSibling;
            }

            // Placeholder marks where the rebuilt list tree goes.
            $anchor = $dom->createComment('lists');
            $item['p']->parentNode->insertBefore($anchor, $item['p']);

            /** @var array<int, array{list: \DOMElement, depth: int, tag: string}> $stack */
            $stack = [];
            foreach ($group as $g) {
                $consumed->attach($g['p']);

                while ($stack !== [] && $g['depth'] < end($stack)['depth']) {
                    array_pop($stack);
                }

                $top = $stack === [] ? null : end($stack);
                if ($top === null || $g['depth'] > $top['depth'] || $g['tag'] !== $top['tag']) {
                    $list = $dom->createElement($g['tag']);
                    if ($top === null) {
                        $anchor->parentNode->insertBefore($list, $anchor);
                    } elseif ($g['depth'] > $top['depth']) {
                        // Nest inside the previous item (or the list itself if
                        // a malformed document starts a group above depth 0).
                        ($top['list']->lastChild ?? $top['list'])->appendChild($list);
                    } else {
                        // Type flip at the same depth: sibling list.
                        array_pop($stack);
                        $top['list']->parentNode->insertBefore($list, $top['list']->nextSibling);
                    }
                    $stack[] = ['list' => $list, 'depth' => $g['depth'], 'tag' => $g['tag']];
                }

                $li = $dom->createElement('li');
                end($stack)['list']->appendChild($li);
                $li->appendChild($g['p']); // moves the <p> out of the flat run
            }

            $anchor->parentNode->removeChild($anchor);
        }

        // Strip every sentinel (grouped or not) so PUA chars never reach content.
        foreach ($xpath->query('//text()') as $text) {
            if (str_contains($text->textContent, self::LIST_MARK_START)) {
                $text->textContent = preg_replace(self::LIST_MARK_RE, '', $text->textContent);
            }
        }
    }

    /**
     * Single DOM pass over PhpWord's HTML that (1) normalises inline-style
     * whitespace and (2) rehosts embedded base64 images.
     *
     * PhpWord emits underline as `text-decoration: underline ;` — the stray
     * space before the semicolon means tiptap-php captures the value as
     * "underline " (trailing space) and its exact match against "underline"
     * fails, silently dropping the underline mark. Trimming each declaration's
     * value fixes that for every style-based mark, not just underline.
     */
    private function normalizeHtml(string $html, ?int $uploadedById): string
    {
        $dom = new DOMDocument();
        // Suppress malformed HTML warnings from PhpWord output
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

        // Lists first: the style pass below may wrap <p> elements in
        // <strong>/<em>, which would break the sibling grouping.
        $this->rebuildLists($dom);

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//*[@style]') as $el) {
            $style = $this->normalizeStyle($el->getAttribute('style'));

            $wrapTags = [];
            if (preg_match('/\bfont-weight:\s*bold\b/i', $style)) {
                $wrapTags[] = 'strong';
                $style = preg_replace('/\bfont-weight:\s*bold\b/i', '', $style);
            }
            if (preg_match('/\bfont-style:\s*italic\b/i', $style)) {
                $wrapTags[] = 'em';
                $style = preg_replace('/\bfont-style:\s*italic\b/i', '', $style);
            }
            if (preg_match('/\btext-decoration:\s*underline\b/i', $style)) {
                $wrapTags[] = 'u';
                $style = preg_replace('/\btext-decoration:\s*underline\b/i', '', $style);
            }
            if (preg_match('/\btext-decoration:\s*line-through\b/i', $style)) {
                $wrapTags[] = 's';
                $style = preg_replace('/\btext-decoration:\s*line-through\b/i', '', $style);
            }

            $style = trim(str_replace(';;', ';', $style), ' ;');

            if ($style === '') {
                $el->removeAttribute('style');
            } else {
                $el->setAttribute('style', $style);
            }

            if ($wrapTags) {
                $current = $el;
                foreach ($wrapTags as $tag) {
                    $wrapper = $dom->createElement($tag);
                    $current->parentNode->insertBefore($wrapper, $current);
                    $wrapper->appendChild($current);
                    $current = $wrapper;
                }
            }
        }

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');
            if (str_starts_with($src, 'data:image/')) {
                $url = $this->assetStore->storeDataUri($src, $uploadedById);
                if ($url) {
                    $img->setAttribute('src', $url);
                }
            }
        }

        return $dom->saveHTML();
    }

    /** Trim whitespace around each `prop: value` declaration in a style string. */
    private function normalizeStyle(string $style): string
    {
        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$prop, $value] = explode(':', $declaration, 2);
            $prop  = trim($prop);
            $value = trim($value);

            if ($prop !== '' && $value !== '') {
                $declarations[] = "{$prop}: {$value}";
            }
        }

        return implode('; ', $declarations);
    }

    /** Pull just the body content out of a full HTML document. */
    private function extractBody(string $html): string
    {
        $body = preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $m) ? $m[1] : $html;

        // PhpWord renders a Title (depth 0) as <h0>, which TipTap doesn't support
        // and would otherwise flatten into a stray top-level text node. Promote it
        // to a level-1 heading.
        return str_ireplace(['<h0>', '</h0>'], ['<h1>', '</h1>'], $body);
    }
}
