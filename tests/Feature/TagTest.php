<?php

use App\Models\Document;
use App\Models\Tag;

test('a tag can be created and gets a slug', function () {
    login();

    $this->post('/tags', ['name' => 'Production'])->assertRedirect();

    expect(Tag::firstWhere('name', 'Production')?->slug)->toBe('production');
});

test('tag names are required', function () {
    login();
    $this->post('/tags', ['name' => ''])->assertSessionHasErrors('name');
});

test('tags attach to documents polymorphically', function () {
    login();
    $document = Document::factory()->create();
    $tag = Tag::factory()->create();

    $document->tags()->attach($tag);

    $this->assertDatabaseHas('taggables', [
        'tag_id' => $tag->id,
        'taggable_id' => $document->id,
        'taggable_type' => Document::class,
    ]);
    expect($tag->documents()->count())->toBe(1);
});

test('a tag can be deleted', function () {
    login();
    $tag = Tag::factory()->create();

    $this->delete("/tags/{$tag->id}")->assertRedirect();

    $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
});
