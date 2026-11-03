<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'title' => $title,
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'position' => 0,
            'content' => self::tiptap(fake()->paragraph()),
            'metadata' => [],
        ];
    }

    /** A minimal valid TipTap document wrapping a single paragraph of text. */
    public static function tiptap(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }
}
