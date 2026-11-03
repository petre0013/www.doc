<?php

namespace App\Support;

use Caxy\HtmlDiff\HtmlDiff;
use Caxy\HtmlDiff\HtmlDiffConfig;

/**
 * HtmlDiff whose table diffing goes through Utf8TableDiff (see there for why).
 * The word-level diff itself is mb-safe; only the table path needed fixing.
 */
class Utf8HtmlDiff extends HtmlDiff
{
    public static function create($oldText, $newText, ?HtmlDiffConfig $config = null)
    {
        $diff = new self($oldText, $newText);

        if (null !== $config) {
            $diff->setConfig($config);
        }

        return $diff;
    }

    protected function diffTables($oldText, $newText)
    {
        return Utf8TableDiff::create($oldText, $newText, $this->config)->build();
    }
}
