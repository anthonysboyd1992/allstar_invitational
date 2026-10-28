<?php

namespace Database\Factories;

use App\Models\Race;
use App\Models\RaceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Race>
 */
class RaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'race_type_id' => RaceType::factory(),
            'name' => 'Heat '.fake()->numberBetween(1, 9),
            'lineup_name' => null,
            'laps' => 8,
            'transfers' => null,
            'sponsor' => null,
            'sort_order' => 0,
        ];
    }
}
