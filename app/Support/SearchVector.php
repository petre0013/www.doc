<?php

namespace App\Support;

/**
 * The single SQL definition of a document's full-text `search_vector`.
 *
 * Both the live `DocumentObserver` (on every save) and the `search:reindex`
 * command derive the vector from the SAME source — the rendered `content_html`
 * (weight B), with the title weighted A. `content_html` is canonical: it is the
 * only place a diagram's node labels/name surface as text (RenderDocument emits
 * them), so indexing the raw TipTap JSON instead silently drops them. Keep this
 * the one derivation; do not re-introduce a second one in either caller.
 */
class SearchVector
{
    /**
     * The `setweight(...) || setweight(...)` expression for a documents UPDATE.
     * Expects two bound parameters (the text-search language, twice) before any
     * trailing `WHERE` parameters.
     */
    public static function expression(): string
    {
        return "
            setweight(to_tsvector(?, coalesce(title, '')), 'A') ||
            setweight(to_tsvector(?,
                regexp_replace(coalesce(content_html, ''), '<[^>]+>', ' ', 'g')
            ), 'B')
        ";
    }
}
