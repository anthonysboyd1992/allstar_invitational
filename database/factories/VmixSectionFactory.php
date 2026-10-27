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

    public function placeholder(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_placeholder' => true,
            'show_order' => VmixSection::nextShowOrder(),
        ]);
    }

    public function template(): static
    {
        return $this->state(fn (array $attributes) => ['is_template' => true]);
    }

    /**
     * A run-of-show item of the given template, without copied structure. Use VmixSection::createItem() to copy groups and fields.
     */
    public function itemOf(VmixSection $template): static
    {
        return $this->state(fn (array $attributes) => ['template_id' => $template->id, 'show_order' => VmixSection::nextShowOrder()]);
    }

    public function onAir(): static
    {
        return $this->state(fn (array $attributes) => ['on_air' => true]);
    }
}
