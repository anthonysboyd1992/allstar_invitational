<?php

namespace Database\Factories;

use App\Models\TimingFeedResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimingFeedResult>
 */
class TimingFeedResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_key' => fake()->uuid(),
            'name' => 'Heat 1',
            'flag' => 'Finish',
            'is_live' => false,
            'standings' => [
                [
                    'position' => 1,
                    'number' => '12',
                    'transponder' => '52474',
                    'registration' => '12',
                    'laps' => 8,
                    'total_time' => '00:02:14.321',
                    'first_name' => 'Ann',
                    'last_name' => 'First',
                ],
            ],
            'applied_to_race_id' => null,
            'applied_at' => null,
        ];
    }

    /**
     * The session the Windows puller is currently sending.
     */
    public function live(): static
    {
        return $this->state(['is_live' => true]);
    }

    /**
     * Hidden from Live Timing and the Results picker.
     */
    public function ignored(): static
    {
        return $this->state([
            'is_live' => false,
            'ignored_at' => now(),
        ]);
    }

    /**
     * Already imported onto a race, so it no longer belongs on the board.
     */
    public function applied(): static
    {
        return $this->state([
            'is_live' => false,
            'applied_at' => now(),
        ]);
    }
}
