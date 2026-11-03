<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'path' => 'attachments/'.Str::ulid().'.pdf',
            'disk' => 'local',
            'original_name' => fake()->slug().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 5_000_000),
            'checksum' => hash('sha256', Str::random()),
            'position' => 0,
        ];
    }
}
