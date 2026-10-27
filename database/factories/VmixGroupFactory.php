<?php

namespace Database\Factories;

use App\Models\VmixGroup;
use App\Models\VmixSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VmixGroup>
 */
class VmixGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vmix_section_id' => VmixSection::factory(),
            'name' => ucfirst(fake()->word()),
            'sort_order' => 0,
        ];
    }
}
