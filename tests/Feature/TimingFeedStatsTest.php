<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\Setting;
use App\Models\TimingFeedResult;
use App\Services\TimingFeedStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimingFeedStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fastest_lap_of_the_race_uses_the_best_lap_time(): void
    {
        $session = TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['best_lap' => '16.900']),
                $this->standing(2, '7', 'Bob', 'Second', ['best_lap' => '16.321']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertSame('#7 Bob Second', $stats['fastest_race']['label']);
        $this->assertSame('16.321', $stats['fastest_race']['time']);
        $this->assertSame('22.057', $stats['fastest_race']['speed']);
    }

    public function test_fastest_lap_of_the_day_names_the_session_it_came_from(): void
    {
        TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['best_lap' => '16.100']),
            ],
        ]);
        $heat2 = TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 2',
            'standings' => [
                $this->standing(1, '7', 'Bob', 'Second', ['best_lap' => '16.800']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($heat2, $heat2->orderedStandings());

        $this->assertSame('#12 Ann First', $stats['fastest_day']['label']);
        $this->assertSame('Heat 1', $stats['fastest_day']['session']);
        $this->assertSame('22.360', $stats['fastest_day']['speed']);
        $this->assertSame('#7 Bob Second', $stats['fastest_race']['label']);
    }

    public function test_hard_charger_and_passes_are_omitted_when_no_race_matches_the_session(): void
    {
        $session = TimingFeedResult::factory()->create([
            'name' => 'Mystery Feature',
            'standings' => [
                $this->standing(1, '7', 'Bob', 'Second', ['start_position' => 6, 'passes' => 5]),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertFalse($stats['matched']);
        $this->assertNull($stats['hard_charger']);
        $this->assertNull($stats['hard_charger_day']);
        $this->assertNull($stats['most_passes']);
        $this->assertNull($stats['transfer_after']);
    }

    public function test_hard_charger_and_passes_use_the_matched_race_lineup(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'laps' => 8]);
        $ann = Competitor::factory()->create(['car_number' => '12', 'transponder' => '111']);
        $bob = Competitor::factory()->create(['car_number' => '7', 'transponder' => '222']);
        $race->syncLineup([$ann->id, $bob->id]);

        $session = TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '7', 'Bob', 'Second', ['transponder' => '222']),
                $this->standing(2, '12', 'Ann', 'First', ['transponder' => '111']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertTrue($stats['matched']);
        $this->assertSame('#7 Bob Second', $stats['hard_charger']['label']);
        $this->assertSame(1, $stats['hard_charger']['gain']);
        $this->assertSame(1, $stats['most_passes']['passes']);
        $this->assertSame(8, $stats['race_laps']);
    }

    public function test_hard_charger_of_another_race_is_listed_for_the_day(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $heat1 = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $heat2 = Race::factory()->for($type)->create(['name' => 'Heat 2']);
        $field = Competitor::factory()->count(8)->create();
        $cara = Competitor::factory()->create(['car_number' => '44', 'transponder' => '44000', 'first_name' => 'Cara', 'last_name' => 'Charge']);
        $bob = Competitor::factory()->create(['car_number' => '7', 'transponder' => '7000', 'first_name' => 'Bob', 'last_name' => 'Second']);
        $heat1->syncLineup([...$field->pluck('id')->all(), $cara->id]);
        $heat2->syncLineup([$bob->id, ...$field->take(1)->pluck('id')->all()]);

        TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '44', 'Cara', 'Charge', ['transponder' => '44000']),
            ],
        ]);
        $live = TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 2',
            'standings' => [
                $this->standing(1, '7', 'Bob', 'Second', ['transponder' => '7000']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($live, $live->orderedStandings());

        $this->assertSame('#44 Cara Charge', $stats['hard_charger_day']['label']);
        $this->assertSame(8, $stats['hard_charger_day']['gain']);
        $this->assertSame('Heat 1', $stats['hard_charger_day']['session']);
    }

    public function test_most_passes_today_adds_lineup_gains_across_matched_races(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $heat1 = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $heat2 = Race::factory()->for($type)->create(['name' => 'Heat 2']);
        $ann = Competitor::factory()->create(['car_number' => '12', 'transponder' => '12000', 'first_name' => 'Ann', 'last_name' => 'First']);
        $bob = Competitor::factory()->create(['car_number' => '7', 'transponder' => '7000', 'first_name' => 'Bob', 'last_name' => 'Second']);
        $heat1->syncLineup([$bob->id, $ann->id]);
        $heat2->syncLineup([$bob->id, Competitor::factory()->create()->id, $ann->id]);

        TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['transponder' => '12000']),
            ],
        ]);
        $live = TimingFeedResult::factory()->create([
            'name' => 'Heat 2',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['transponder' => '12000']),
                $this->standing(2, '7', 'Bob', 'Second', ['transponder' => '7000']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($live, $live->orderedStandings());

        $this->assertSame('#12 Ann First', $stats['most_passes']['label']);
        $this->assertSame(3, $stats['most_passes']['passes']);
    }

    public function test_transfer_line_uses_the_last_transferring_position(): void
    {
        $heats = RaceType::factory()->create(['has_results' => true]);
        $feature = RaceType::factory()->create(['name' => 'Feature', 'has_results' => true]);
        Race::factory()->for($heats)->create(['name' => 'Heat 1']);
        RaceTypeTransferRule::factory()->create([
            'race_type_id' => $heats->id,
            'destination_race_type_id' => $feature->id,
            'position_from' => 1,
            'position_to' => 4,
        ]);
        $session = TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First'),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertSame(4, $stats['transfer_after']);
    }

    public function test_fastest_lap_of_the_day_skips_ignored_sessions(): void
    {
        TimingFeedResult::factory()->ignored()->create([
            'name' => 'Practice',
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['best_lap' => '15.100']),
            ],
        ]);
        $heat = TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'standings' => [
                $this->standing(1, '7', 'Bob', 'Second', ['best_lap' => '16.800']),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($heat, $heat->orderedStandings());

        $this->assertSame('#7 Bob Second', $stats['fastest_race']['label']);
        $this->assertNull($stats['fastest_day']);
    }

    public function test_diff_is_behind_the_leader_and_gap_is_behind_the_car_ahead(): void
    {
        $standings = collect([
            $this->standing(1, '12', 'Ann', 'First', ['laps' => 5, 'total_time' => '00:01:00.000']),
            $this->standing(2, '7', 'Bob', 'Second', ['laps' => 5, 'total_time' => '00:01:01.234']),
            $this->standing(3, '21', 'Cara', 'Third', ['laps' => 5, 'total_time' => '00:01:01.500']),
            $this->standing(4, '44', 'Dan', 'Fourth', ['laps' => 4, 'total_time' => '00:00:50.000']),
        ]);

        $rows = (new TimingFeedStats)->withIntervals($standings)->all();

        $this->assertSame('', $rows[0]['differential']);
        $this->assertSame('', $rows[0]['gap']);
        $this->assertSame('+1.234', $rows[1]['differential']);
        $this->assertSame('+1.234', $rows[1]['gap']);
        $this->assertSame('+1.500', $rows[2]['differential']);
        $this->assertSame('+0.266', $rows[2]['gap']);
        $this->assertSame('1 lap', $rows[3]['differential']);
        $this->assertSame('1 lap', $rows[3]['gap']);
    }

    public function test_speed_uses_track_length_and_last_lap(): void
    {
        Setting::put(Setting::TrackLength, '0.250');

        $rows = (new TimingFeedStats)->withIntervals(collect([
            $this->standing(1, '12', 'Ann', 'First', ['last_lap' => '16.500']),
            $this->standing(2, '7', 'Bob', 'Second', ['last_lap' => '00:00:05.624']),
            $this->standing(3, '21', 'Cara', 'Third', ['last_lap' => '']),
        ]))->all();

        $this->assertSame('54.545', $rows[0]['speed']);
        $this->assertSame('160.028', $rows[1]['speed']);
        $this->assertSame('', $rows[2]['speed']);
    }

    public function test_zero_lap_times_are_blank_and_best_stays_the_fastest_valid_lap(): void
    {
        $stats = new TimingFeedStats;

        $this->assertSame('', $stats->usableLap('00:00:00.000'));
        $this->assertSame('', $stats->usableLap('0'));
        $this->assertSame('5.370', $stats->fastestLapTime('00:00:00.000', '6.090', '5.370'));
        $this->assertSame('5.200', $stats->fastestLapTime('00:00:05.370', '5.200'));

        $rows = $stats->withTrackedLaps(
            collect([
                $this->standing(1, '12', 'Ann', 'First', [
                    'last_lap' => '00:00:00.000',
                    'best_lap' => '00:00:00.000',
                ]),
            ]),
            collect([
                $this->standing(1, '12', 'Ann', 'First', [
                    'last_lap' => '6.090',
                    'best_lap' => '5.370',
                ]),
            ]),
        )->all();

        $this->assertSame('6.090', $rows[0]['last_lap']);
        $this->assertSame('5.370', $rows[0]['best_lap']);
    }

    public function test_laps_use_the_leader_and_scheduled_distance(): void
    {
        $session = TimingFeedResult::factory()->create([
            'name' => 'Heat 1',
            'race_laps' => 20,
            'standings' => [
                $this->standing(1, '12', 'Ann', 'First', ['laps' => 5]),
                $this->standing(2, '7', 'Bob', 'Second', ['laps' => 4]),
            ],
        ]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertSame(5, $stats['laps']);
        $this->assertSame(20, $stats['race_laps']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function standing(int $position, string $number, string $first, string $last, array $overrides = []): array
    {
        return array_merge([
            'position' => $position,
            'number' => $number,
            'transponder' => $number.'000',
            'registration' => $number,
            'laps' => 3,
            'total_time' => '00:01:00.000',
            'first_name' => $first,
            'last_name' => $last,
            'last_lap' => '16.500',
            'best_lap' => '',
            'start_position' => 0,
            'passes' => 0,
        ], $overrides);
    }
}
