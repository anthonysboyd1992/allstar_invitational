<?php

namespace Database\Factories;

use App\Models\VmixField;
use App\Models\VmixGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VmixField>
 */
class VmixFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vmix_group_id' => VmixGroup::factory(),
            'key' => fake()->unique()->word().'_'.fake()->word(),
            'type' => VmixField::TypeText,
            'value' => fake()->sentence(3),
            'live_value' => fn (array $attributes) => $attributes['value'],
            'needs_update' => false,
            'sort_order' => 0,
        ];
    }

    public function image(?string $path = null): static
    {
        return $this->state(fn (array $attributes) => ['type' => VmixField::TypeImage, 'value' => $path, 'live_value' => $path, 'width' => 400, 'height' => 300]);
    }

    public function needsUpdate(): static
    {
        return $this->state(fn (array $attributes) => ['needs_update' => true]);
    }
}
