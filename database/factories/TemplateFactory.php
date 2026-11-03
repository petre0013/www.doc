<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'        => ucfirst($this->faker->unique()->words(2, true)),
            'description' => $this->faker->sentence(),
            'content'     => [
                'type'    => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $this->faker->sentence()]]],
                ],
            ],
        ];
    }
}
