<?php

use App\Services\RenderDocument;
use App\Support\TipTap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite image srcs saved as absolute URLs to our own assets
 * (`https://<host>/storage/assets/…`, from copy/paste inside the app) back to
 * `/storage/assets/…`, so they load whatever scheme/host the instance is served
 * on. Written straight to the table: a data repair, not an edit — no version
 * bump, no snapshot, no audit event, no "edited by" change.
 *
 * document_versions are deliberately left alone: sign-off hashes pin their
 * content. They are rewritten at render time (RenderDocument / ResizableImage).
 */
return new class extends Migration
{
    public function up(): void
    {
        $like = '%://%/storage/%';

        DB::table('documents')->whereRaw('content::text LIKE ?', [$like])
            ->select('id', 'content')->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $content = json_decode($row->content, true);
                    $fixed   = TipTap::normalize($content);
                    if ($fixed === $content) {
                        continue;
                    }
                    DB::table('documents')->where('id', $row->id)->update([
                        'content'      => json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'content_html' => RenderDocument::toHtml($fixed),
                    ]);
                }
            });

        DB::table('templates')->whereRaw('content::text LIKE ?', [$like])
            ->select('id', 'content')->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $content = json_decode($row->content, true);
                    $fixed   = TipTap::normalize($content);
                    if ($fixed !== $content) {
                        DB::table('templates')->where('id', $row->id)->update([
                            'content' => json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible data repair; the relative form is the canonical one.
    }
};
