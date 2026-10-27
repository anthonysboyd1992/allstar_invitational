<?php

namespace Database\Factories;

use App\Models\VmixSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VmixSection>
 */
class VmixSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'key' => str_replace(' ', '_', strtolower($name)),
            'on_air' => false,
            'sort_order' => 0,
        ];
    }

    public function onAir(): static
    {
        return $this->state(fn (array $attributes) => ['on_air' => true]);
    }
}
