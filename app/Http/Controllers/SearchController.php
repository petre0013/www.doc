<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $q = trim((string) $request->get('q', ''));

        $results = [];

        if ($q !== '') {
            $results = array_merge(
                $this->searchDocuments($q),
                $this->searchWorkspaces($q),
                $this->searchTags($q),
            );

            // Sort: documents by rank desc first, then workspaces, then tags
            usort($results, fn ($a, $b) => ($b['rank'] ?? 0) <=> ($a['rank'] ?? 0));
        }

        return Inertia::render('Search/Index', [
            'q'       => $q,
            'results' => $results,
        ]);
    }

    private function searchDocuments(string $q): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $lang = config('database.search_language', 'english');

        // Lexize the query with the SAME parser + dictionary that built the
        // documents' search_vector, so multi-part tokens (IPs, version numbers,
        // hostnames — 192.168.5.5, v1.2.3, foo.bar.com) tokenize identically on
        // both sides. Splitting the query ourselves broke this: `192.168.5.5`
        // became `192:* & 168:* & 5:* & 5:*`, but Postgres indexes the IP as ONE
        // lexeme, so the AND could never match. unnest(to_tsvector(...)) yields
        // exactly the indexed lexemes; `:*` preserves prefix search
        // ("serv" → server); quote_literal keeps odd lexemes safe and — since they
        // come from to_tsvector, never the raw query — guarantees no tsquery
        // operator (`< > | = ~`) can reach to_tsquery (the 500 the old splitter
        // was guarding against). Empty/stopword-only queries → ' ' (no FTS match,
        // ILIKE title fallback still applies), same as before.
        $tsQueryString = DB::selectOne(
            "SELECT COALESCE(string_agg(quote_literal(lexeme) || ':*', ' & '), ' ') AS q
               FROM unnest(to_tsvector(?, ?))",
            [$lang, $q]
        )->q;

        // FTS for indexed documents, ILIKE title fallback for unindexed ones.
        // ts_headline runs over the tag-STRIPPED text (the same derivation the
        // search vector indexes — see SearchVector) so highlights can't land
        // inside tag attributes/URLs and the excerpt carries no markup beyond
        // the <mark> tags ts_headline itself inserts.
        $rows = DB::select("
            SELECT
                d.id,
                d.title,
                d.slug,
                d.workspace_id,
                d.updated_at,
                w.name  AS workspace_name,
                u.name  AS updated_by_name,
                (
                    SELECT COALESCE(
                        JSON_AGG(JSON_BUILD_OBJECT('id', t2.id, 'name', t2.name) ORDER BY t2.name),
                        '[]'::json
                    )
                    FROM taggables tg2
                    JOIN tags t2 ON t2.id = tg2.tag_id
                    WHERE tg2.taggable_id = d.id
                      AND tg2.taggable_type = 'App\\Models\\Document'
                ) AS tags,
                CASE
                    WHEN d.search_vector IS NOT NULL
                         AND d.search_vector @@ to_tsquery(?, ?)
                    THEN ts_rank(d.search_vector, to_tsquery(?, ?))
                    ELSE 0.05
                END AS rank,
                CASE
                    WHEN d.search_vector IS NOT NULL
                         AND d.search_vector @@ to_tsquery(?, ?)
                    THEN ts_headline(
                        ?,
                        regexp_replace(COALESCE(d.content_html, ''), '<[^>]+>', ' ', 'g'),
                        to_tsquery(?, ?),
                        'MaxWords=30, MinWords=15, StartSel=<mark>, StopSel=</mark>, HighlightAll=false'
                    )
                    ELSE NULL
                END AS excerpt
            FROM documents d
            JOIN workspaces w ON w.id = d.workspace_id
            LEFT JOIN users u ON u.id = d.updated_by_id
            WHERE
                d.deleted_at IS NULL
                AND w.deleted_at IS NULL
                AND (
                    (d.search_vector IS NOT NULL AND d.search_vector @@ to_tsquery(?, ?))
                    OR d.title ILIKE ?
                )
            ORDER BY rank DESC
            LIMIT 20
        ", [$lang, $tsQueryString, $lang, $tsQueryString, $lang, $tsQueryString, $lang, $lang, $tsQueryString, $lang, $tsQueryString, $like]);

        return array_map(function ($row) {
            $row = (array) $row;
            $row['type'] = 'document';
            $row['tags'] = json_decode($row['tags'] ?? '[]', true) ?? [];
            if ($row['excerpt']) {
                $row['excerpt'] = html_entity_decode(strip_tags($row['excerpt']), ENT_QUOTES | ENT_HTML5);
            }
            return $row;
        }, $rows);
    }

    private function searchWorkspaces(string $q): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $rows = Workspace::where('name', 'ILIKE', $like)
            ->orWhere('description', 'ILIKE', $like)
            ->withCount('documents')
            ->limit(5)
            ->get(['id', 'name', 'description', 'slug'])
            ->toArray();

        return array_map(fn ($row) => array_merge($row, [
            'type'           => 'workspace',
            'rank'           => 0.8,
            'excerpt'        => $row['description'] ?? null,
            'workspace_name' => null,
        ]), $rows);
    }

    private function searchTags(string $q): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $rows = Tag::where('name', 'ILIKE', $like)
            ->withCount('documents')
            ->limit(5)
            ->get(['id', 'name', 'slug'])
            ->toArray();

        return array_map(fn ($row) => array_merge($row, [
            'type'           => 'tag',
            'rank'           => 0.6,
            'excerpt'        => null,
            'workspace_name' => null,
        ]), $rows);
    }
}
