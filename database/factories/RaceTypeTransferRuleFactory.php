<?php

namespace Database\Factories;

use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaceTypeTransferRule>
 */
class RaceTypeTransferRuleFactory extends Factory
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
            'position_from' => 1,
            'position_to' => 1,
            'destination_race_type_id' => RaceType::factory(),
            'distribute' => RaceTypeTransferRule::DistributeAppend,
            'destination_start_slot' => null,
            'sort_order' => 0,
        ];
    }
}
