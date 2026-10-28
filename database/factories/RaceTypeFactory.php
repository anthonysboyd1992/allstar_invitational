<?php

namespace Database\Factories;

use App\Models\RaceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceType>
 */
class RaceTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Hot Laps', 'Heat', 'Dash', 'B-Main', 'A-Main']),
            'race_name' => null,
            'sort_order' => 0,
        ];
    }
}
