<?php

namespace Database\Factories;

use App\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competitor>
 */
class CompetitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pill_number' => fake()->unique()->numberBetween(1, 999),
            'car_number' => (string) fake()->numberBetween(1, 99).fake()->optional(0.3, '')->randomLetter(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'hometown' => fake()->city().' '.fake()->stateAbbr(),
            'primary_division' => null,
            'weight' => fake()->randomFloat(1, 130, 260),
            'accolades' => null,
            'announcer_details' => null,
            'transponder' => (string) fake()->numberBetween(1000000, 9999999),
        ];
    }
}
