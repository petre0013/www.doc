<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => fake()->randomElement(['created', 'updated', 'deleted']),
            'auditable_type' => Workspace::class,
            'auditable_id' => Workspace::factory(),
            'workspace_id' => function (array $attributes) {
                return $attributes['auditable_type'] === Workspace::class 
                    ? $attributes['auditable_id'] 
                    : null;
            },
            'context' => [],
            'ip' => fake()->ipv4(),
            'created_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
