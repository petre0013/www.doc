<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConversionJob>
 */
class ConversionJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'direction' => 'export',
            'format' => fake()->randomElement(['pdf', 'docx']),
            'status' => 'pending',
        ];
    }
}
