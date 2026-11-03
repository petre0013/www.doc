<?php

namespace App\Support;

use Caxy\HtmlDiff\HtmlDiffConfig;
use Caxy\HtmlDiff\Table\TableDiff;

/**
 * caxy's TableDiff mangles table-cell text two ways; this subclass fixes both
 * at the source (see also Utf8HtmlDiff, which routes tables here):
 *
 * 1. createDocumentWithHtml() parses via
 *    loadHTML(htmlspecialchars_decode(iconv('UTF-8', 'ISO-8859-1//IGNORE', htmlentities(...))))
 *    whose iconv step silently DROPS every character outside Latin-1 (emoji,
 *    CJK…) — including on the INTERNAL re-parse of diffed cell HTML, so no
 *    escaping at the outer boundary survives. Replaced with a lossless parse:
 *    non-ASCII goes in as numeric entities, so libxml sees pure ASCII and no
 *    charset guessing can corrupt it.
 *
 * 2. diffCells() pipes both sides through
 *    mb_convert_encoding(..., 'UTF-8', 'HTML-ENTITIES'), un-escaping user text
 *    one level: "&amp;" becomes a bare "&" (libxml warning — Laravel promotes
 *    it to an ErrorException) and "&lt;img onerror=…&gt;" becomes LIVE markup
 *    once setInnerHtml() re-parses the diff (stored XSS in the compare view).
 *    It existed to recover non-Latin-1 characters mangled by (1); with (1)
 *    fixed it is pure damage, so the override drops it.
 */
class Utf8TableDiff extends TableDiff
{
    public static function create($oldText, $newText, ?HtmlDiffConfig $config = null)
    {
        $diff = new self($oldText, $newText);

        if (null !== $config) {
            $diff->setConfig($config);
        }

        return $diff;
    }

    protected function createDocumentWithHtml($text)
    {
        $dom = new \DOMDocument();
        $dom->loadHTML(mb_encode_numericentity($text, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));

        return $dom;
    }

    protected function diffCells($oldCell, $newCell, $usingExtraRow = false)
    {
        $diffCell = $this->getNewCellNode($oldCell, $newCell);

        $oldContent = $oldCell ? $this->getInnerHtml($oldCell->getDomNode()) : '';
        $newContent = $newCell ? $this->getInnerHtml($newCell->getDomNode()) : '';

        $htmlDiff = Utf8HtmlDiff::create($oldContent, $newContent, $this->config);
        $diff = $htmlDiff->build();

        $this->setInnerHtml($diffCell, $diff);

        if (null === $newCell) {
            $diffCell->setAttribute('class', trim($diffCell->getAttribute('class').' del'));
        }

        if (null === $oldCell) {
            $diffCell->setAttribute('class', trim($diffCell->getAttribute('class').' ins'));
        }

        if ($usingExtraRow) {
            $diffCell->setAttribute('class', trim($diffCell->getAttribute('class').' extra-row'));
        }

        return $diffCell;
    }
}
