<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The page title an imported file gets when nothing better is available.
 *
 * Shared by the upload request (which shows it while the file converts) and the
 * queue job (which keeps it when the file carries no title of its own), so both
 * sides derive the SAME name from the same filename — the job used to recover it
 * by stripping the placeholder prefix back off the title.
 */
class ImportTitle
{
    /** Placeholder shown in the tree while the file is still converting. */
    public const PLACEHOLDER_PREFIX = 'Importing ';

    /** "network-runbook.docx" → "Network Runbook". */
    public static function fromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return Str::title(str_replace('-', ' ', $base));
    }

    /** The same name, marked as still converting. */
    public static function placeholder(string $filename): string
    {
        return self::PLACEHOLDER_PREFIX . self::fromFilename($filename);
    }

    /** Is this title still the untouched placeholder (i.e. safe to overwrite)? */
    public static function isPlaceholder(?string $title): bool
    {
        return str_starts_with((string) $title, self::PLACEHOLDER_PREFIX);
    }
}
