<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Link>
 */
class LinkFactory extends Factory
{
    public function definition(): array
    {
        $target = Document::factory();

        return [
            'source_document_id' => Document::factory(),
            'target_document_id' => $target,
            'target_title' => fake()->sentence(3),
        ];
    }
}
