<?php

use App\Models\Asset;
use App\Models\Document;
use Database\Factories\DocumentFactory;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/** An asset row backed by a real file on the fake public disk. */
function makeAsset(array $attrs = []): Asset
{
    $asset = Asset::factory()->create(array_merge(['disk' => 'public'], $attrs));
    Storage::disk('public')->put($asset->path, 'bytes');

    // Default assets to outside the grace window so they're prune-eligible;
    // a test wanting a "fresh" asset overrides created_at.
    $asset->created_at = $attrs['created_at'] ?? now()->subDays(2);
    $asset->saveQuietly();

    return $asset;
}

/** A document whose content embeds the asset's URL, like the editor stores it. */
function docWithImage(Asset $asset, array $attrs = []): Document
{
    return Document::factory()->create(array_merge([
        'content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'figure 1']]],
                ['type' => 'image', 'attrs' => ['src' => '/storage/'.$asset->path]],
            ],
        ],
    ], $attrs));
}

test('it deletes an unreferenced asset older than the grace window', function () {
    $orphan = makeAsset();

    $this->artisan('assets:prune')->assertSuccessful();

    expect(Asset::find($orphan->id))->toBeNull();
    Storage::disk('public')->assertMissing($orphan->path);
});

test('it keeps an asset referenced by a live document', function () {
    $used = makeAsset();
    docWithImage($used);

    $this->artisan('assets:prune')->assertSuccessful();

    expect(Asset::find($used->id))->not->toBeNull();
    Storage::disk('public')->assertExists($used->path);
});

test('it keeps an asset referenced only by a historical version', function () {
    $used = makeAsset();
    $doc = docWithImage($used);

    // Remove the image from the current content; the first version still has it.
    $doc->update(['content' => DocumentFactory::tiptap('image removed')]);
    expect($doc->fresh()->content_html)->not->toContain($used->path);

    $this->artisan('assets:prune')->assertSuccessful();

    expect(Asset::find($used->id))->not->toBeNull();
});

test('it keeps an asset referenced by a trashed (restorable) document', function () {
    $used = makeAsset();
    $doc = docWithImage($used);
    $doc->trashSubtree();

    $this->artisan('assets:prune')->assertSuccessful();

    expect(Asset::find($used->id))->not->toBeNull();
});

test('it spares a freshly uploaded asset within the grace window', function () {
    $fresh = makeAsset(['created_at' => now()->subMinutes(5)]);

    $this->artisan('assets:prune')->assertSuccessful();

    expect(Asset::find($fresh->id))->not->toBeNull();
});

test('the grace window is configurable via --hours', function () {
    $orphan = makeAsset(['created_at' => now()->subHours(3)]);

    // Default 24h keeps it; a tighter window prunes it.
    $this->artisan('assets:prune')->assertSuccessful();
    expect(Asset::find($orphan->id))->not->toBeNull();

    $this->artisan('assets:prune', ['--hours' => 1])->assertSuccessful();
    expect(Asset::find($orphan->id))->toBeNull();
});

test('dry-run reports orphans without deleting them', function () {
    $orphan = makeAsset();

    $this->artisan('assets:prune', ['--dry-run' => true])->assertSuccessful();

    expect(Asset::find($orphan->id))->not->toBeNull();
    Storage::disk('public')->assertExists($orphan->path);
});
