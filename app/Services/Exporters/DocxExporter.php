<?php

namespace App\Services\Exporters;

use App\Contracts\ExporterContract;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;

class DocxExporter implements ExporterContract
{
    // Target on-page width for a diagram, in EMU (1 in = 914400). ~6in fills the
    // content column of both A4 and US-Letter (1in margins) without overflow; the
    // diagram scales to this preserving aspect, so it's no longer tiny.
    private const DIAGRAM_WIDTH_EMU = 5486400;

    private PhpWord $word;

    /** @var \PhpOffice\PhpWord\Element\Section */
    private $section;

    /** @var array<string> */
    private array $tempFiles = [];

    /** @var array<string, array> */
    private array $svgs = [];

    /**
     * Context stack so nested lists know their depth + type.
     * Each entry: ['type' => 'bullet'|'ordered', 'depth' => int]
     */
    private array $listStack = [];

    public function export(Document $document): string
    {
        $this->word    = $this->makeWord();
        $this->section = $this->word->addSection([
            'marginTop'    => 1440, // 1 inch in twips
            'marginBottom' => 1440,
            'marginLeft'   => 1440,
            'marginRight'  => 1440,
        ]);

        // No running header: exports are neutral documents (the title is the
        // in-body H1). The footer keeps only the page number.
        $footer = $this->section->addFooter();
        $footer->addPreserveText(
            'Page {PAGE} of {NUMPAGES}',
            ['size' => 9, 'color' => '8A8A8A'],
            ['alignment' => 'center']
        );

        $this->processNodes($document->content['content'] ?? []);

        $slug     = $document->slug;
        $filename = "exports/docx/{$slug}-" . now()->format('YmdHis') . '.docx';
        $this->word->getCompatibility()->setOoxmlVersion(15);
        $this->word->getSettings()->setDocumentProtection(null);

        $tempPath = sys_get_temp_dir() . "/{$slug}.docx";

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($this->word, 'Word2007');
        $writer->save($tempPath);

        $this->injectSvgs($tempPath);

        Storage::disk('local')->put($filename, file_get_contents($tempPath));
        @unlink($tempPath);

        foreach ($this->tempFiles as $tmp) {
            @unlink($tmp);
        }

        return $filename;
    }

    // -------------------------------------------------------------------------

    private function makeWord(): PhpWord
    {
        $w = new PhpWord();
        $w->setDefaultFontName('Century Gothic');
        $w->setDefaultFontSize(11);

        // Heading styles — neutral black/gray chrome, like the PDF export.
        foreach ([1 => 24, 2 => 18, 3 => 14, 4 => 12] as $level => $size) {
            $w->addTitleStyle($level, [
                'bold'  => true,
                'size'  => $size,
                'color' => '1A1A1A',
            ], [
                'spaceAfter'  => 120,
                'spaceBefore' => $level === 1 ? 0 : 240,
            ]);
        }

        $w->addFontStyle('Code', [
            'name'  => 'Courier New',
            'size'  => 9,
            'color' => '333333',
        ]);

        return $w;
    }

    // -------------------------------------------------------------------------

    private function processNodes(array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->processNode($node);
        }
    }

    private function processNode(array $node): void
    {
        $type = $node['type'] ?? '';

        match ($type) {
            'heading'      => $this->addHeading($node),
            'paragraph'    => $this->addParagraph($node),
            'bulletList'   => $this->addList($node, 'bullet'),
            'orderedList'  => $this->addList($node, 'ordered'),
            'taskList'     => $this->addTaskList($node),
            'blockquote'   => $this->addBlockquote($node),
            'callout'      => $this->addCallout($node),
            'codeBlock'    => $this->addCodeBlock($node),
            'table'        => $this->addTable($node),
            'horizontalRule' => $this->addHr(),
            'image'        => $this->addImage($node),
            'networkDiagram' => $this->addNetworkDiagram($node),
            default        => null,
        };
    }

    private function addHeading(array $node): void
    {
        $level = $node['attrs']['level'] ?? 1;
        $level = min(max($level, 1), 4);
        $text  = $this->extractText($node);
        $this->section->addTitle(htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'), $level);
    }

    private function addParagraph(array $node): void
    {
        $children = $node['content'] ?? [];
        if (empty($children)) {
            $this->section->addTextBreak(1);
            return;
        }

        $textRun = $this->section->addTextRun(['spaceAfter' => 100]);
        foreach ($children as $inline) {
            $this->addInline($textRun, $inline);
        }
    }

    private function addList(array $node, string $type): void
    {
        $depth = count($this->listStack);
        $this->listStack[] = ['type' => $type, 'depth' => $depth];

        foreach ($node['content'] ?? [] as $item) {
            $this->addListItem($item, $type, $depth);
        }

        array_pop($this->listStack);
    }

    private function addListItem(array $node, string $type, int $depth): void
    {
        $listType = $type === 'orderedList' ? \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER : \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED;

        foreach ($node['content'] ?? [] as $child) {
            $childType = $child['type'] ?? '';
            if ($childType === 'bulletList') {
                $this->addList($child, 'bullet');
            } elseif ($childType === 'orderedList') {
                $this->addList($child, 'ordered');
            } elseif ($childType === 'paragraph') {
                $run = $this->section->addListItemRun($depth, $listType, ['spaceAfter' => 100]);
                foreach ($child['content'] ?? [] as $inlineChild) {
                    $this->addInline($run, $inlineChild);
                }
            } else {
                $run = $this->section->addListItemRun($depth, $listType, ['spaceAfter' => 100]);
                $this->addInline($run, $child);
            }
        }
    }

    /**
     * Task lists render as indented paragraphs with ☐/☑ glyph prefixes —
     * PhpWord has no native checkbox list, and Word's own checkbox lists are
     * content controls we don't want to hand-build. Nested task lists deepen
     * the indent like addList() does.
     */
    private function addTaskList(array $node, int $depth = 0): void
    {
        foreach ($node['content'] ?? [] as $item) {
            $checked = (bool) ($item['attrs']['checked'] ?? false);
            $glyph   = $checked ? '☑' : '☐';

            foreach ($item['content'] ?? [] as $child) {
                $childType = $child['type'] ?? '';
                if ($childType === 'taskList') {
                    $this->addTaskList($child, $depth + 1);
                } elseif ($childType === 'paragraph') {
                    $run = $this->section->addTextRun([
                        'indentation' => ['left' => 240 + $depth * 360],
                        'spaceAfter'  => 60,
                    ]);
                    $run->addText($glyph . ' ', ['size' => 11, 'color' => $checked ? '333333' : '555555']);
                    $this->addInline($run, $child);
                }
            }
        }
    }

    /** Callouts render as a single shaded table cell, tinted by kind. */
    private function addCallout(array $node): void
    {
        // Word wants bgColor without '#'. Values mirror the app's light-theme
        // callout token triads.
        [$bg, $color] = match ($node['attrs']['kind'] ?? 'info') {
            'success' => ['DAE6D4', '4B6840'],
            'warning' => ['FAF1E2', '7A5520'],
            'danger'  => ['F3E7E2', 'B5573E'],
            default   => ['EDF2EA', '364E2E'],
        };

        $table = $this->section->addTable(['borderColor' => $bg, 'borderSize' => 6, 'cellMargin' => 120]);
        $cell  = $table->addRow()->addCell(null, ['bgColor' => $bg]);

        foreach ($node['content'] ?? [] as $child) {
            $text = $this->extractText($child);
            $cell->addText(
                htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                ['color' => $color, 'size' => 10],
                ['spaceAfter' => 60]
            );
        }

        $this->section->addTextBreak(1);
    }

    private function addBlockquote(array $node): void
    {
        foreach ($node['content'] ?? [] as $child) {
            $text    = $this->extractText($child);
            $para    = $this->section->addText(
                htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                ['italic' => true, 'color' => '555555', 'size' => 10],
                ['indentation' => ['left' => 720], 'spaceAfter' => 100]
            );
        }
    }

    private function addCodeBlock(array $node): void
    {
        $text = $this->extractText($node);

        // Split lines so each line is a separate text in a shaded table cell
        $table = $this->section->addTable(['borderColor' => 'D6D6D6', 'borderSize' => 6]);
        $row   = $table->addRow();
        $cell  = $row->addCell(null, ['bgColor' => 'F5F5F5']);

        foreach (explode("\n", $text) as $line) {
            $cell->addText(
                htmlspecialchars($line !== '' ? $line : ' '),
                ['name' => 'Courier New', 'size' => 9, 'color' => '333333']
            );
        }

        $this->section->addTextBreak(1);
    }

    private function addTable(array $node): void
    {
        $table = $this->section->addTable([
            'borderColor' => 'D6D6D6',
            'borderSize'  => 6,
            'cellMargin'  => 80,
        ]);

        foreach ($node['content'] ?? [] as $rowNode) {
            $row = $table->addRow();
            foreach ($rowNode['content'] ?? [] as $cellNode) {
                $isHeader = ($cellNode['type'] ?? '') === 'tableHeader';
                $cell     = $row->addCell(null, $isHeader ? ['bgColor' => 'F0F0F0'] : []);
                $text     = $this->extractText($cellNode);
                $cell->addText(htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'), $isHeader ? ['bold' => true, 'size' => 10] : ['size' => 10]);
            }
        }

        $this->section->addTextBreak(1);
    }

    private function addHr(): void
    {
        $this->section->addText('', [], ['borderBottomColor' => 'D6D6D6', 'borderBottomSize' => 6]);
    }

    private function addImage(array $node): void
    {
        $this->embedImage($node['attrs']['src'] ?? '', ['width' => 400, 'height' => 300, 'ratio' => true]);
    }

    private function addNetworkDiagram(array $node): void
    {
        $svg = \App\Support\DiagramSvg::render(
            json_decode(json_encode($node['attrs']['graph'] ?? null), true)
        );

        if ($svg) {
            $uuid = uniqid('svg_');
            $this->svgs[$uuid] = $svg;
            
            $width = $svg['width'];
            $height = $svg['height'];
            $this->section->addText("SVG_DIAGRAM_{$width}_{$height}_{$uuid}", ['size' => 1, 'color' => 'FFFFFF'], ['alignment' => 'center']);
        }

        $name  = trim((string) ($node['attrs']['name'] ?? ''));
        $label = $name !== '' ? $name : 'Untitled diagram';
        $this->section->addText(
            htmlspecialchars($label),
            ['italic' => true, 'size' => 9, 'color' => '555555'],
            ['alignment' => 'center', 'spaceAfter' => 120]
        );
    }

    /** Embed an image from local storage or a base64 data URL. */
    private function embedImage(string $src, array $style): void
    {
        if (!$src) return;

        $src = \App\Services\RenderDocument::resolveImageToDataUri($src);

        if (str_starts_with($src, 'data:image/')) {
            if (preg_match('/^data:image\/(\w+);base64,/', $src, $type)) {
                $data = substr($src, strpos($src, ',') + 1);
                $data = base64_decode($data);
                if ($data !== false) {
                    $ext = $type[1] === 'jpeg' ? 'jpg' : $type[1];
                    $tempFile = sys_get_temp_dir() . '/' . uniqid('docx_img_') . '.' . $ext;
                    file_put_contents($tempFile, $data);
                    $this->tempFiles[] = $tempFile;
                    $this->section->addImage($tempFile, $style);
                }
            }
            return;
        }
    }

    private function injectSvgs(string $docxPath): void
    {
        if (empty($this->svgs)) return;

        $oldZip = new \ZipArchive();
        if ($oldZip->open($docxPath) !== true) return;
        
        $newDocxPath = $docxPath . '.new';
        $newZip = new \ZipArchive();
        if ($newZip->open($newDocxPath, \ZipArchive::CREATE) !== true) {
            $oldZip->close();
            return;
        }

        $fallbackIds = [];
        $scriptPath = base_path('process_svg.js');
        $relsToInject = [];
        $fallbackIds = [];
        $mediaToAdd = [];

        foreach ($this->svgs as $uuid => $svg) {
            $svgFileIn = sys_get_temp_dir() . '/' . uniqid('svg_in_') . '.svg';
            $svgFileOut = sys_get_temp_dir() . '/' . uniqid('svg_out_') . '.svg';
            $pngFile = $svgFileOut . '_fallback.png';

            file_put_contents($svgFileIn, $svg['svg']);
            // The out/png files must survive until $newZip->close() reads them;
            // export() unlinks everything in $tempFiles afterwards.
            array_push($this->tempFiles, $svgFileIn, $svgFileOut, $pngFile);

            // Trailing "2" = 2x PNG supersampling, matching the PDF embed scale.
            $output = shell_exec("node " . escapeshellarg($scriptPath) . " " . escapeshellarg($svgFileIn) . " " . escapeshellarg($svgFileOut) . " " . escapeshellarg($pngFile) . " 2 2>&1");

            // A missing output means the Node pass died (e.g. the @resvg native
            // binary for this platform isn't installed). Log it loudly — this
            // used to fail silently, shipping DOCX exports with no diagram and
            // nothing in the logs to explain why.
            if (! file_exists($svgFileOut) || ! file_exists($pngFile)) {
                \Illuminate\Support\Facades\Log::warning('DOCX export: process_svg.js produced no output; diagram omitted.', [
                    'output' => trim((string) $output),
                ]);
            }

            if (file_exists($svgFileOut) && file_exists($pngFile)) {
                $fallbackId = 'rIdFallback' . uniqid();
                $relId = 'rIdSvg' . uniqid();
                
                $mediaToAdd['word/media/' . basename($pngFile)] = $pngFile;
                $mediaToAdd['word/media/' . basename($svgFileOut)] = $svgFileOut;
                
                $relsToInject[] = '
                    <Relationship Id="'.$fallbackId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.basename($pngFile).'"/>
                    <Relationship Id="'.$relId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.basename($svgFileOut).'"/>
                ';
                
                $fallbackIds[$uuid] = [
                    'png' => $fallbackId,
                    'svg' => $relId
                ];
            }
        }

        // Copy everything to the new ZIP, making modifications along the way
        for ($i = 0; $i < $oldZip->numFiles; $i++) {
            $name = $oldZip->getNameIndex($i);
            $content = $oldZip->getFromIndex($i);

            if ($name === 'word/document.xml') {
                $content = preg_replace_callback('/<w:t[^>]*>SVG_DIAGRAM_(\d+)_(\d+)_([a-zA-Z0-9_]+)<\/w:t>/', function($m) use ($fallbackIds) {
                    // Scale to the page content width preserving aspect (the graph's
                    // intrinsic px size otherwise renders the diagram tiny).
                    $pxW = (int) $m[1];
                    $pxH = (int) $m[2];
                    $scale = $pxW > 0 ? self::DIAGRAM_WIDTH_EMU / ($pxW * 9525) : 1.0;
                    $width = (int) round($pxW * 9525 * $scale);
                    $height = (int) round($pxH * 9525 * $scale);
                    $uuid = $m[3];
                    // No rendered media for this diagram (Node pass failed) —
                    // strip the marker rather than leave hidden junk text in
                    // the document.
                    if (!isset($fallbackIds[$uuid])) return '<w:t></w:t>';
                    $fallbackId = $fallbackIds[$uuid]['png'];
                    $relId = $fallbackIds[$uuid]['svg'];
                    return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:docPr id="'.rand(1000,9999).'" name="Diagram"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="'.rand(1000,9999).'" name="Diagram"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$fallbackId.'"><a:extLst><a:ext uri="{96DAC541-7B7A-43D3-8B79-37D633B846F1}"><asvg:svgBlip xmlns:asvg="http://schemas.microsoft.com/office/drawing/2016/SVG/main" r:embed="'.$relId.'"/></a:ext></a:extLst></a:blip><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
                }, $content);
                $content = str_replace('<w:document xmlns:ve="', '<w:document xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:asvg="http://schemas.microsoft.com/office/drawing/2016/SVG/main" mc:Ignorable="asvg" xmlns:ve="', $content);
            } 
            elseif ($name === 'word/settings.xml') {
                $content = str_replace('w:val="12"', 'w:val="15"', $content);
            } 
            elseif ($name === '[Content_Types].xml') {
                if (strpos($content, 'Extension="svg"') === false) $content = str_replace('</Types>', '<Default Extension="svg" ContentType="image/svg+xml"/></Types>', $content);
                if (strpos($content, 'Extension="png"') === false) $content = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $content);
            } 
            elseif ($name === 'word/_rels/document.xml.rels') {
                if (!empty($relsToInject)) {
                    $content = str_replace('</Relationships>', implode('', $relsToInject) . '</Relationships>', $content);
                }
            }

            $newZip->addFromString($name, $content);
        }

        // Add the new media files
        foreach ($mediaToAdd as $zipPath => $localPath) {
            $newZip->addFile($localPath, $zipPath);
        }

        $oldZip->close();
        $newZip->close();

        // Overwrite original zip
        rename($newDocxPath, $docxPath);
    }

    // -------------------------------------------------------------------------
    // Inline content helpers

    /**
     * @param \PhpOffice\PhpWord\Element\TextRun $run
     */
    private function addInline(mixed $run, array $node): void
    {
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            $marks = $node['marks'] ?? [];
            $style = $this->marksToFontStyle($marks);
            $text  = $node['text'] ?? '';
            $run->addText(htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'), $style);
            return;
        }

        if ($type === 'hardBreak') {
            $run->addTextBreak();
            return;
        }

        if ($type === 'wikiLink') {
            // Neutral export: a wiki-link is just underlined text, like any link.
            $title = $node['attrs']['title'] ?? '';
            $run->addText(htmlspecialchars($title, ENT_XML1 | ENT_COMPAT, 'UTF-8'), ['underline' => 'single']);
            return;
        }

        // Recurse into any other inline nodes
        foreach ($node['content'] ?? [] as $child) {
            $this->addInline($run, $child);
        }
    }

    private function marksToFontStyle(array $marks): array
    {
        $style = [];
        foreach ($marks as $mark) {
            match ($mark['type'] ?? '') {
                'bold'      => $style['bold'] = true,
                'italic'    => $style['italic'] = true,
                'underline' => $style['underline'] = 'single',
                'strike'    => $style['strikethrough'] = true,
                'code'      => $style['name'] = 'Courier New',
                'textStyle' => $this->applyColor($style, $mark['attrs']['color'] ?? null),
                'highlight' => $this->applyHighlight($style, $mark['attrs']['color'] ?? null),
                default     => null,
            };
        }
        return $style;
    }

    /** Apply the text-colour mark (textStyle) as a Word font colour. */
    private function applyColor(array &$style, ?string $color): void
    {
        if ($hex = $this->normalizeHex($color)) {
            $style['color'] = $hex;
        }
    }

    /**
     * Apply the highlight mark. Word's `<w:highlight>` only accepts 16 named
     * colours, so we use character shading (`<w:shd w:fill>`) to keep the exact
     * highlight hex the editor stored.
     */
    private function applyHighlight(array &$style, ?string $color): void
    {
        if ($hex = $this->normalizeHex($color)) {
            $style['shading'] = ['pattern' => 'clear', 'fill' => $hex];
        }
    }

    /** Normalise a CSS hex colour (#rgb / #rrggbb) to a 6-digit RRGGBB, or null. */
    private function normalizeHex(?string $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }
        $hex = ltrim(trim($color), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : null;
    }

    private function extractText(?array $node): string
    {
        if (!$node) return '';
        if (($node['type'] ?? '') === 'text') return $node['text'] ?? '';
        return implode('', array_map([$this, 'extractText'], $node['content'] ?? []));
    }
}
