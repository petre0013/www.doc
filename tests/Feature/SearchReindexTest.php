<?php

use App\Models\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Database\Factories\DocumentFactory;

test('search:reindex rebuilds search vectors for all documents', function () {
    login();

    // Create a document with known content
    $document = Document::factory()->create([
        'title'   => 'Obscure Title',
        'content' => DocumentFactory::tiptap('Peculiar content'),
    ]);

    // Sabotage the search vector to be empty manually so we can prove the command fixes it
    DB::statement('UPDATE documents SET search_vector = NULL WHERE id = ?', [$document->id]);

    $vectorBefore = DB::scalar('SELECT search_vector FROM documents WHERE id = ?', [$document->id]);
    expect($vectorBefore)->toBeNull();

    // Run the command
    $exitCode = Artisan::call('search:reindex');
    expect($exitCode)->toBe(0);

    $vectorAfter = DB::scalar('SELECT search_vector FROM documents WHERE id = ?', [$document->id]);
    expect($vectorAfter)->not->toBeNull();

    // The document should now be findable
    $this->get('/search?q=Peculiar')
         ->assertOk()
         ->assertInertia(fn ($page) => $page->has('results', 1)->where('results.0.id', $document->id));
});
