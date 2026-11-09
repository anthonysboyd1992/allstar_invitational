<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\TimingFeedResult;
use App\Models\User;
use App\Services\TimingFeedStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class RaceInvertTest extends TestCase
{
    use RefreshDatabase;

    public function test_invert_top_n_reverses_the_leaders_and_leaves_the_rest(): void
    {
        $drivers = $this->drivers(4);
        $race = $this->featureWith($drivers);
        $feed = $this->liveFeed($drivers->reverse()->values(), 'SCOREBOARD');

        $summary = $race->invertFrom($feed, 2);
        $race->refresh()->load('entries.competitor');

        $this->assertSame(4, $summary['inverted']);
        $this->assertSame(0, $summary['unmatched']);
        $this->assertSame(['4', '3', '2', '1'], $this->breakNumbers($race));
        $this->assertSame(['3', '4', '2', '1'], $this->restartNumbers($race));
        $this->assertSame([1, 2, 3, 4], $this->originalStarts($race));
        $this->assertTrue($race->isInverted());
        $this->assertSame($feed->id, $race->live_feed_id);
        $this->assertSame(2, $race->invert_count);
    }

    public function test_full_field_invert_reverses_cars_on_the_live_board_and_sends_missing_cars_last(): void
    {
        $onTrack = $this->drivers(3);
        $inPits = $this->drivers(2, 4);
        $race = $this->featureWith($onTrack->concat($inPits));
        $feed = $this->liveFeed($onTrack->reverse()->values(), 'SCOREBOARD');

        $race->invertFrom($feed, 0);
        $race->refresh()->load('entries.competitor');

        $this->assertSame(['3', '2', '1', '4', '5'], $this->breakNumbers($race));
        $this->assertSame(['1', '2', '3', '4', '5'], $this->restartNumbers($race));
    }

    public function test_unmatched_live_cars_are_counted_and_not_inserted(): void
    {
        $drivers = $this->drivers(2);
        $race = $this->featureWith($drivers);
        $stranger = Competitor::factory()->create(['car_number' => '99', 'transponder' => '99000']);
        $feed = TimingFeedResult::factory()->live()->create([
            'name' => 'SCOREBOARD',
            'standings' => [
                $this->standingFor($stranger, 1),
                $this->standingFor($drivers[1], 2),
                $this->standingFor($drivers[0], 3),
            ],
        ]);

        $summary = $race->invertFrom($feed, 0);

        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame(2, $summary['inverted']);
        $this->assertNull($race->entries()->whereHas('competitor', fn ($query) => $query->where('car_number', '99'))->first());
        $this->assertSame(['1', '2'], $this->restartNumbers($race->refresh()->load('entries.competitor')));
    }

    public function test_undo_invert_clears_restart_spots_and_keeps_original_starts(): void
    {
        $drivers = $this->drivers(3);
        $race = $this->featureWith($drivers);
        $feed = $this->liveFeed($drivers, 'SCOREBOARD');
        $race->invertFrom($feed, 0);

        $race->undoInvert();
        $race->refresh()->load('entries');

        $this->assertFalse($race->isInverted());
        $this->assertNull($race->live_feed_id);
        $this->assertSame([1, 2, 3], $this->originalStarts($race));
        $this->assertTrue($race->entries->every(fn ($entry): bool => $entry->break_position === null && $entry->invert_start_position === null));
    }

    public function test_hard_charger_adds_both_halves_and_does_not_count_the_invert(): void
    {
        $drivers = $this->drivers(8);
        $race = $this->featureWith($drivers);
        $break = $drivers->reverse()->values();
        $feed = $this->liveFeed($break, 'SCOREBOARD');
        $race->invertFrom($feed, 8);

        $charger = $drivers[7]->fresh();
        $entry = $race->entries()->where('competitor_id', $charger->id)->first();
        $this->assertSame(8, $entry->start_position);
        $this->assertSame(1, $entry->break_position);
        $this->assertSame(8, $entry->invert_start_position);
        $this->assertSame(7, $entry->liveCharge(8));
        $this->assertSame(11, $entry->liveCharge(4));

        $restartStandings = $drivers->values()->map(fn (Competitor $driver, int $index): array => $this->standingFor($driver, $index + 1));
        $session = TimingFeedResult::factory()->live()->create([
            'name' => 'SCOREBOARD',
            'standings' => $restartStandings->all(),
        ]);
        $race->update(['live_feed_id' => $session->id]);

        $stats = (new TimingFeedStats)->summarize($session, $session->orderedStandings());

        $this->assertSame('#8 Driver 8', $stats['hard_charger']['label']);
        $this->assertSame(7, $stats['hard_charger']['gain']);
        $this->assertSame(7, $stats['most_passes']['passes']);
    }

    public function test_live_feed_id_matches_a_session_whose_name_does_not_match_the_race(): void
    {
        $drivers = $this->drivers(2);
        $race = $this->featureWith($drivers);
        $feed = $this->liveFeed($drivers->reverse()->values(), 'Race SCOREBOARD-02:47PM');
        $race->invertFrom($feed, 0);

        $stats = (new TimingFeedStats)->summarize($feed->fresh(), $feed->orderedStandings());

        $this->assertTrue($stats['matched']);
        $this->assertSame('#2 Driver 2', $stats['hard_charger']['label']);
        $this->assertSame(2, $stats['hard_charger']['gain']);
    }

    public function test_results_page_inverts_from_the_live_board_and_can_undo(): void
    {
        $this->actingAs(User::factory()->create());
        $drivers = $this->drivers(4);
        $race = $this->featureWith($drivers);
        $this->liveFeed($drivers->reverse()->values(), 'SCOREBOARD');

        Livewire::test('pages::results')
            ->assertSee('Invert')
            ->call('openInvert', $race->id)
            ->set('invertTop', 2)
            ->assertSee('Confirm invert')
            ->call('invertFromLive', $race->id)
            ->assertSee('Restart')
            ->assertSee('Undo invert')
            ->assertDontSee('Confirm invert');

        $this->assertTrue($race->fresh()->isInverted());
        $this->assertSame(['3', '4', '2', '1'], $this->restartNumbers($race->fresh()->load('entries.competitor')));

        Livewire::test('pages::results')
            ->call('undoInvert', $race->id)
            ->assertSee('Lineup')
            ->assertSee('Invert')
            ->assertDontSee('Undo invert');

        $this->assertFalse($race->fresh()->isInverted());
    }

    /**
     * @param  Collection<int, Competitor>  $drivers
     */
    private function featureWith(Collection $drivers): Race
    {
        $type = RaceType::factory()->create(['name' => 'Feature', 'has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Feature']);
        $race->syncLineup($drivers->pluck('id')->all());

        return $race->fresh(['entries.competitor']);
    }

    /**
     * @param  Collection<int, Competitor>  $order
     */
    private function liveFeed(Collection $order, string $name): TimingFeedResult
    {
        return TimingFeedResult::factory()->live()->create([
            'name' => $name,
            'standings' => $order->values()->map(fn (Competitor $driver, int $index): array => $this->standingFor($driver, $index + 1))->all(),
        ]);
    }

    /**
     * @return Collection<int, Competitor>
     */
    private function drivers(int $count, int $from = 1): Collection
    {
        return collect(range($from, $from + $count - 1))->map(fn (int $number): Competitor => Competitor::factory()->create([
            'car_number' => (string) $number,
            'transponder' => (string) (1000 + $number),
            'first_name' => 'Driver',
            'last_name' => (string) $number,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function standingFor(Competitor $driver, int $position): array
    {
        return [
            'position' => $position,
            'number' => $driver->car_number,
            'transponder' => $driver->transponder,
            'registration' => $driver->car_number,
            'laps' => 10,
            'total_time' => '00:01:00.000',
            'first_name' => $driver->first_name,
            'last_name' => $driver->last_name,
            'last_lap' => '16.500',
            'best_lap' => '16.200',
            'start_position' => 0,
            'passes' => 0,
        ];
    }

    /**
     * @return list<int>
     */
    private function originalStarts(Race $race): array
    {
        return $race->entries->sortBy('start_position')->values()->pluck('start_position')->all();
    }

    /**
     * @return list<string>
     */
    private function breakNumbers(Race $race): array
    {
        return $race->entries->sortBy('break_position')->values()->map(fn ($entry): string => (string) $entry->competitor->car_number)->all();
    }

    /**
     * @return list<string>
     */
    private function restartNumbers(Race $race): array
    {
        return $race->entries->sortBy('invert_start_position')->values()->map(fn ($entry): string => (string) $entry->competitor->car_number)->all();
    }
}
