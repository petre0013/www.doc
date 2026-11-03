<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'path' => 'assets/'.Str::uuid().'.png',
            'disk' => 'local',
            'mime' => 'image/png',
            'size' => fake()->numberBetween(1000, 5_000_000),
            'checksum' => hash('sha256', Str::random()),
        ];
    }
}
