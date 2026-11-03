<?php

namespace App\Services;

use App\Support\Ssrf;
use Illuminate\Support\Facades\Http;
use Tiptap\Core\Node;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Highlight;
use Tiptap\Marks\Link;
use Tiptap\Marks\TextStyle;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Table;
use Tiptap\Nodes\TableCell;
use Tiptap\Nodes\TableHeader;
use Tiptap\Nodes\TableRow;
use Tiptap\Utils\InlineStyle;
use Tiptap\Extensions\TextAlign;

class RenderDocument
{
    public static bool $embedImages = false;

    /**
     * Neutral "Image Unavailable" SVG, as a data URI. Used as the export-time
     * stand-in for images that can't be embedded safely (private-host URLs,
     * fetch failures, paths escaping the public disk) and as the browser-side
     * onerror fallback — so a bad reference degrades visibly, never into a raw
     * URL the PDF/DOCX pipeline would fetch unguarded.
     */
    public const UNAVAILABLE_IMAGE = "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23FBFAF5%22 stroke=%22%23E2DFD4%22 stroke-width=%222%22 stroke-dasharray=%228%22 rx=%228%22 /%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22system-ui, sans-serif%22 font-size=%2214%22 font-weight=%22500%22 fill=%22%238E938E%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22%3EImage Unavailable%3C/text%3E%3C/svg%3E";

    /** Convert TipTap JSON to HTML — single canonical path for all consumers. */
    public static function toHtml(?array $doc): string
    {
        if (! $doc) {
            return '';
        }

        return self::editor()->setContent($doc)->getHTML();
    }

    /** Convert HTML to TipTap JSON using the identical schema. */
    public static function fromHtml(string $html): array
    {
        return self::editor()->setContent($html)->getDocument();
    }

    private static function editor(): Editor
    {
        return new Editor([
            'extensions' => [
                new StarterKit,
                new Underline,
                new Link,
                new ColoredTextStyleMark,
                new Highlight(['multicolor' => true]),
                new ResizableImageNode,
                new NetworkDiagramNode,
                new Table,
                new TableRow,
                new CustomTableHeaderNode,
                new CustomTableCellNode,
                new WikiLinkNode,
                new TextAlign(['types' => ['heading', 'paragraph']]),
                new \Tiptap\Nodes\TaskList,
                new TaskItemNode,
                new CalloutNode,
            ],
        ]);
    }

    /**
     * Resolve an image reference to a data URI for export-time embedding.
     *
     * Two sources are allowed: files on the public disk (via their /storage/
     * URL, contained to that directory), and external http(s) URLs that pass
     * the SSRF guard — public hosts only, no redirects, connection pinned to
     * the validated IPs (CLAUDE.md rule 6; exports run content-controlled srcs
     * on a queue worker that can reach hosts the author can't).
     *
     * Anything that can't be embedded safely returns UNAVAILABLE_IMAGE — never
     * the original src, which downstream fetchers (Dompdf) would hit unguarded.
     */
    public static function resolveImageToDataUri(string $src): string
    {
        if (str_starts_with($src, 'data:')) {
            return $src;
        }

        try {
            $content = null;
            $mime = null;

            if (str_starts_with($src, '/storage/')) {
                $root = realpath(storage_path('app/public'));
                $path = realpath(storage_path('app/public/' . substr($src, strlen('/storage/'))));

                // realpath resolves ../ — embed only if the target actually
                // lives under the public disk.
                if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                    $content = file_get_contents($path);
                    $mime = mime_content_type($path) ?: null;
                }
            } elseif (filter_var($src, FILTER_VALIDATE_URL)) {
                // Follow a few redirects MANUALLY, re-validating every hop through
                // the SSRF guard. Ssrf::fetchOptions disables Guzzle's auto-redirect
                // on purpose (a 30x could bounce us to an internal host the up-front
                // check never saw); many legitimate image hosts (CDNs, picsum,
                // imgur) 302 to a storage domain, so we re-run assertPublicUrl on
                // each Location before fetching it.
                $url = $src;
                for ($hop = 0; $hop < 4; $hop++) {
                    $ips = Ssrf::assertPublicUrl($url); // throws 422 on a private hop

                    $response = Http::timeout(5)
                        ->withOptions(Ssrf::fetchOptions($url, $ips))
                        ->get($url);

                    if ($response->redirect()) {
                        $location = $response->header('Location');
                        if ($location === null || $location === '') {
                            break;
                        }
                        $url = self::resolveRedirect($url, $location);
                        continue;
                    }

                    if ($response->successful()) {
                        $content = $response->body();
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->buffer($content) ?: null;
                    }
                    break;
                }
            }

            // Raster images only — SVG is parsed by php-svg-lib during PDF
            // export, which is not a surface to feed attacker-supplied markup.
            if ($content !== null && $mime !== null
                && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }
        } catch (\Throwable $e) {
            // fall through to the placeholder
        }

        return self::UNAVAILABLE_IMAGE;
    }

    /**
     * Resolve a redirect `Location` (which may be absolute, protocol-relative,
     * root-relative, or a bare path) against the URL it came from. The result is
     * re-validated by Ssrf::assertPublicUrl before it's ever fetched.
     */
    private static function resolveRedirect(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location; // already absolute
        }

        $parts  = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;           // protocol-relative
        }
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}"; // root-relative
        }

        $path = $parts['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1) ?: '/';
        return "{$scheme}://{$host}{$port}{$dir}{$location}"; // relative path
    }

    /**
     * Whitelist the color value before it lands in an inline `style` attribute.
     * The editor only ever emits hex, but `documents.content` is user-supplied
     * JSON (the update endpoint accepts an arbitrary doc), and this HTML is
     * rendered with dangerouslySetInnerHTML in the compare/export views. Attribute
     * escaping stops tag breakout, but not CSS injection — e.g.
     * `red;position:fixed;inset:0` (overlay) or `x;background:url(//evil)` (a
     * server-side beacon on the next export fetch). Accept only well-formed color
     * literals; anything else drops the style entirely rather than passing through.
     */
    public static function safeColor($color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = trim($color);

        $isHex     = (bool) preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color);
        // rgb()/rgba()/hsl()/hsla(): only numbers, separators and units inside — no
        // semicolons, url(), or nested parens that could smuggle further CSS.
        $isFunc    = (bool) preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\/\s]+\)$/i', $color);
        // Bare CSS keyword colours ("red", "rebeccapurple", "transparent").
        $isKeyword = (bool) preg_match('/^[a-z]+$/i', $color);

        return ($isHex || $isFunc || $isKeyword) ? $color : null;
    }
}

/**
 * Renders image nodes with width and alignment preserved in exports.
 * Applies inline styles so PDF/DOCX pipelines see the correct layout.
 */
class ResizableImageNode extends \Tiptap\Nodes\Image
{
    public static $name = 'image';

    public function addAttributes()
    {
        return array_merge(parent::addAttributes(), [
            'align' => [
                'default' => 'left',
            ],
        ]);
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        $attrs = $node->attrs ?? (object) [];
        $src   = $attrs->src   ?? '';
        
        if (\App\Services\RenderDocument::$embedImages) {
            $src = \App\Services\RenderDocument::resolveImageToDataUri($src);
        }

        $alt   = $attrs->alt   ?? '';
        // width is user-controlled JSON — only a number may reach the style string.
        $width = is_numeric($attrs->width ?? null) ? (int) $attrs->width : null;
        $align = $attrs->align ?? 'left';

        $style = 'max-width:100%;display:block;';
        if ($width)              $style .= "width:{$width}px;";
        if ($align === 'center') $style .= 'margin:0 auto;';
        elseif ($align === 'right') $style .= 'margin-left:auto;';
        $fallback = \App\Services\RenderDocument::UNAVAILABLE_IMAGE;

        // `align` must NOT survive as an HTML attribute: it is a legacy
        // presentational attribute that both browsers and Dompdf translate to
        // `float: left|right`, which pulls every image out of the flow — images
        // then pile up side by side (Compare page, PDF) instead of sitting where
        // the author placed them. Alignment is expressed by $style above; the
        // intent rides along as `data-align`, matching the editor's ResizableImage
        // renderHTML.
        unset($HTMLAttributes['align']);

        return ['img', array_merge($HTMLAttributes, [
            'src'        => $src,
            'alt'        => $alt,
            'style'      => $style,
            'data-align' => $align,
            'onerror'    => "if(this.src!=='{$fallback}')this.src='{$fallback}';",
        ])];
    }
}

/**
 * Renders the networkDiagram node for every server-side (no-JS) consumer —
 * read view, PDF/DOCX export, search indexing, version snapshots — none of
 * which can run React Flow. The canonical graph lives in the node's `graph`
 * attr; here we emit only the DERIVED PNG (`imageSrc`). A freshly inserted
 * diagram with no render yet falls back to a labelled placeholder.
 */
class NetworkDiagramNode extends Node
{
    public static $name = 'networkDiagram';

    public function parseHTML()
    {
        return [['tag' => 'div[data-network-diagram]']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        $attrs = $node->attrs ?? (object) [];
        $src   = $attrs->imageSrc ?? null;
        $align = $attrs->align ?? 'left';
        $name  = trim((string) ($attrs->name ?? ''));
        $label = $name !== '' ? $name : 'Untitled diagram';

        // The canonical graph is editor-only, but its node labels are the only
        // searchable text a diagram contributes. Emit them as visually-hidden
        // text so they flow into content_html → the FTS vector (built by both
        // DocumentObserver and search:reindex from the rendered HTML). Hidden so
        // they never show in the read view / PDF, which display the PNG.
        $hidden = static::hiddenLabels($attrs->graph ?? null);

        // The diagram name shows as a caption (and is searchable as visible text);
        // unnamed diagrams caption as "Untitled diagram".
        $caption = '<figcaption class="network-diagram-caption" style="text-align:center;font-size:0.85em;color:#5C625C;margin-top:4px;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</figcaption>';

        $style = 'max-width:100%;display:block;';
        if ($align === 'center')    $style .= 'margin:0 auto;';
        elseif ($align === 'right') $style .= 'margin-left:auto;';

        // Prefer a vector render straight from the canonical graph: it always
        // exists, so PDF/export no longer go blank when the client never captured
        // an `imageSrc` PNG. Inlined as a data-URI SVG (Dompdf renders it via
        // php-svg-lib; arrowheads are <polygon>, not <marker>, for that reason).
        $svg = \App\Support\DiagramSvg::render(
            json_decode(json_encode($attrs->graph ?? null), true)
        );
        if ($svg) {
            $src = 'data:image/svg+xml;base64,' . base64_encode($svg['svg']);
            
            if (\App\Services\RenderDocument::$embedImages) {
                // PDF export: Dompdf's php-svg-lib mis-composes the icon glyphs'
                // nested transforms (icons render rotated) and ignores embedded
                // @font-face, so the PDF never gets our SVG directly. Instead
                // process_svg.js renders it through resvg — the same engine the
                // DOCX export uses — to a 2× PNG (print-sharp at the embedded
                // width). The text-baked SVG remains only as a fallback when the
                // PNG pass fails, and the raw inline SVG below that.
                $scriptPath = base_path('process_svg.js');
                if (file_exists($scriptPath)) {
                    $svgFileIn  = sys_get_temp_dir() . '/' . uniqid('pdf_svg_in_') . '.svg';
                    $svgFileOut = sys_get_temp_dir() . '/' . uniqid('pdf_svg_out_') . '.svg';
                    $pngFile    = sys_get_temp_dir() . '/' . uniqid('pdf_svg_png_') . '.png';

                    file_put_contents($svgFileIn, $svg['svg']);
                    $output = shell_exec('node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($svgFileIn)
                        . ' ' . escapeshellarg($svgFileOut) . ' ' . escapeshellarg($pngFile) . ' 2 2>&1');

                    if (is_file($pngFile) && filesize($pngFile) > 0) {
                        $src = 'data:image/png;base64,' . base64_encode(file_get_contents($pngFile));
                    } else {
                        // Falling back to SVG re-exposes php-svg-lib's transform
                        // bugs (rotated icons) — degrade loudly, like DocxExporter.
                        \Illuminate\Support\Facades\Log::warning('PDF export: process_svg.js produced no PNG; diagram falls back to SVG.', [
                            'output' => trim((string) $output),
                        ]);
                        if (is_file($svgFileOut) && filesize($svgFileOut) > 0) {
                            $src = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($svgFileOut));
                        }
                    }
                    @unlink($pngFile);
                    @unlink($svgFileOut);
                    @unlink($svgFileIn);
                }
            }

            $img = '<img'
                . ' src="' . $src . '"'
                . ' alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" class="network-diagram"'
                . ' style="' . $style . 'width:' . $svg['width'] . 'px;" />';

            return ['content' => '<figure class="network-diagram-figure" style="margin:0;">' . $img . $caption . $hidden . '</figure>'];
        }

        return ['content' => '<div data-network-diagram="true" class="network-diagram-placeholder"></div>' . $caption . $hidden];
    }

    /** Visually-hidden span carrying the diagram's node labels for full-text search. */
    private static function hiddenLabels($graph): string
    {
        $nodes  = (is_object($graph) ? ($graph->nodes ?? []) : []);
        $labels = [];
        foreach ($nodes as $n) {
            $data = is_object($n)
                ? (array) json_decode(json_encode($n->data ?? new \stdClass), true)
                : [];
            ['name' => $name, 'props' => $props] = \App\Support\DiagramSvg::normalizeNode($data);
            if ($name !== '') {
                $labels[] = $name;
            }
            foreach ($props as $p) {
                if ($p['key'] !== '')   $labels[] = $p['key'];
                if ($p['value'] !== '') $labels[] = $p['value'];
            }
        }

        if (! $labels) {
            return '';
        }

        return '<span class="network-diagram-labels"'
            . ' style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;">'
            . htmlspecialchars(implode(' ', $labels), ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

/**
 * tiptap-php extension for the custom wikiLink node.
 * Renders as a styled span (href resolution is a browser-side concern).
 */
class WikiLinkNode extends Node
{
    public static $name = 'wikiLink';

    public function parseHTML()
    {
        return [['tag' => 'span[data-wiki-link]']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        $title = $node->attrs->title ?? '';
        $target_id = $node->attrs->target_id ?? null;

        $attrs = array_merge($HTMLAttributes, [
            'class'          => 'wiki-link',
            'data-wiki-link' => 'true',
            'data-title'     => $title,
        ]);
        
        if ($target_id) {
            $attrs['data-target-id'] = $target_id;
        }

        return ['span', $attrs, 0];
    }

    public function renderText($node): string
    {
        $title = $node->attrs->title ?? '';

        return htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * tiptap-php's TaskItem, adjusted for parity with the JS editor:
 * - `checked` PARSES from the data-checked attribute (the vendor class only
 *   renders it, so a fromHtml round-trip silently lost the state), and always
 *   renders as "true"/"false" — the JS TaskItem and the PDF stylesheet key on
 *   those exact strings.
 * - the checkbox input renders `disabled`: this HTML surfaces in version
 *   snapshots and the compare view, where a click must not pretend to work.
 */
class TaskItemNode extends \Tiptap\Nodes\TaskItem
{
    public function addAttributes()
    {
        return [
            'checked' => [
                'default' => false,
                'parseHTML' => fn ($DOMNode) => $DOMNode->getAttribute('data-checked') === 'true',
                'renderHTML' => fn ($attributes) => [
                    'data-checked' => ($attributes->checked ?? false) ? 'true' : 'false',
                ],
            ],
        ];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return [
            'li',
            \Tiptap\Utils\HTML::mergeAttributes(
                $this->options['HTMLAttributes'],
                $HTMLAttributes,
                ['data-type' => self::$name],
            ),
            [
                'label',
                [
                    'input',
                    [
                        'type' => 'checkbox',
                        'disabled' => 'disabled',
                        'checked' => ($node->attrs->checked ?? false) ? 'checked' : null,
                    ],
                ],
                ['span'],
            ],
            [
                'div',
                0,
            ],
        ];
    }
}

/**
 * Server half of the callout node (resources/js/extensions/Callout.js).
 * Same `<div data-callout="kind" class="callout callout-kind">` signature in
 * both directions; unknown kinds normalise to "info" rather than leaking
 * arbitrary strings into a class name.
 */
class CalloutNode extends Node
{
    public static $name = 'callout';

    public const KINDS = ['info', 'success', 'warning', 'danger'];

    public function addAttributes()
    {
        return [
            'kind' => [
                'default' => 'info',
                'parseHTML' => function ($DOMNode) {
                    $kind = $DOMNode->getAttribute('data-callout');

                    return in_array($kind, self::KINDS, true) ? $kind : 'info';
                },
                'rendered' => false,
            ],
        ];
    }

    public function parseHTML()
    {
        return [['tag' => 'div[data-callout]']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        $kind = $node->attrs->kind ?? 'info';
        if (! in_array($kind, self::KINDS, true)) {
            $kind = 'info';
        }

        return ['div', [
            'data-callout' => $kind,
            'class'        => "callout callout-{$kind}",
        ], 0];
    }
}

/**
 * tiptap-php's base TextStyle mark carries no attributes; the JS editor's
 * Color extension stores a `color` attr on it. Mirror that here so text colour
 * renders as an inline style in the read view and every exporter.
 */
class ColoredTextStyleMark extends TextStyle
{
    public function addAttributes()
    {
        return [
            'color' => [
                'parseHTML' => fn ($DOMNode) => InlineStyle::getAttribute($DOMNode, 'color') ?: null,
                'renderHTML' => function ($attributes) {
                    $color = \App\Services\RenderDocument::safeColor($attributes->color ?? null);

                    return $color ? ['style' => "color: {$color}"] : null;
                },
            ],
        ];
    }
}

trait HasTableAttributes
{
    public function addAttributes()
    {
        return array_merge(parent::addAttributes(), [
            'backgroundColor' => [
                'parseHTML' => fn ($DOMNode) => \Tiptap\Utils\InlineStyle::getAttribute($DOMNode, 'background-color') ?: null,
                'renderHTML' => function ($attributes) {
                    $color = \App\Services\RenderDocument::safeColor($attributes->backgroundColor ?? null);
                    return $color ? ['style' => "background-color: {$color} !important;"] : null;
                },
            ],
            'colwidth' => [
                'parseHTML' => function ($DOMNode) {
                    $width = $DOMNode->getAttribute('data-colwidth');
                    return $width ? [(int) $width] : null;
                },
                'renderHTML' => function ($attributes) {
                    if (empty($attributes->colwidth)) return null;
                    $width = is_array($attributes->colwidth) ? (int) $attributes->colwidth[0] : (int) $attributes->colwidth;
                    return ['data-colwidth' => $width];
                },
            ],
        ]);
    }
}

class CustomTableCellNode extends \Tiptap\Nodes\TableCell
{
    use HasTableAttributes;
}

class CustomTableHeaderNode extends \Tiptap\Nodes\TableHeader
{
    use HasTableAttributes;
}
