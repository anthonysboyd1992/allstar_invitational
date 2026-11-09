<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\Setting;
use App\Models\TimingFeedResult;
use App\Models\User;
use App\TimingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class ResultsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_page_lists_only_types_that_have_results(): void
    {
        RaceType::factory()->create(['name' => 'Hotlaps', 'has_results' => false]);
        $heat = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true]);
        Race::factory()->for($heat)->create(['name' => 'Heat 1']);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Heat')
            ->assertSee('Heat 1')
            ->assertSee('Calculate')
            ->assertDontSee('Hotlaps');
    }

    public function test_clicking_drivers_scores_them_in_finish_order_without_calculating(): void
    {
        $program = $this->heatTransferProgram(score: false);
        $entries = $program['heat1']->entries()->orderBy('start_position')->get();

        Livewire::test('pages::results')
            ->call('scoreNext', $entries[2]->id)
            ->call('scoreNext', $entries[0]->id)
            ->call('scoreNext', $entries[1]->id)
            ->assertHasNoErrors();

        $this->assertSame(1, $entries[2]->fresh()->finish_position);
        $this->assertSame(2, $entries[0]->fresh()->finish_position);
        $this->assertSame(3, $entries[1]->fresh()->finish_position);
        $this->assertSame(0, $program['dash']->entries()->count());
        $this->assertSame(0, $program['feature']->entries()->count());
        $this->assertSame(TimingStatus::Complete, $program['heat1']->fresh()->timing_status);
    }

    public function test_results_can_set_a_race_back_to_lineups(): void
    {
        $program = $this->heatTransferProgram(score: false);

        Livewire::test('pages::results')
            ->call('updateTimingStatus', $program['heat1']->id, 'lineups');

        $this->assertSame(TimingStatus::Lineups, $program['heat1']->fresh()->timing_status);
    }

    public function test_unscoring_a_driver_closes_the_finish_order(): void
    {
        $program = $this->heatTransferProgram();
        $entries = $program['heat1']->entries()->orderBy('start_position')->get();

        Livewire::test('pages::results')->call('unscore', $entries[0]->id);

        $this->assertNull($entries[0]->fresh()->finish_position);
        $this->assertSame(1, $entries[1]->fresh()->finish_position);
        $this->assertSame(2, $entries[2]->fresh()->finish_position);
    }

    public function test_dragging_finishers_rewrites_order(): void
    {
        $program = $this->heatTransferProgram();
        $entries = $program['heat1']->entries()->orderBy('start_position')->get();

        Livewire::test('pages::results')->call('sortFinishes', $entries[2]->id, 0);

        $this->assertSame(1, $entries[2]->fresh()->finish_position);
        $this->assertSame(2, $entries[0]->fresh()->finish_position);
        $this->assertSame(3, $entries[1]->fresh()->finish_position);
    }

    public function test_scored_finishers_show_the_race_they_transfer_into(): void
    {
        $program = $this->heatTransferProgram();

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Lineup')
            ->assertSee('Results')
            ->assertSee('Dash')
            ->assertSee('Feature')
            ->assertSee('B-Main 1')
            ->assertSee('B-Main 2');
    }

    public function test_heat_calculate_fills_dash_feature_and_staggers_b_mains(): void
    {
        $program = $this->heatTransferProgram();
        $heat1 = $program['heat1Drivers'];
        $heat2 = $program['heat2Drivers'];

        Livewire::test('pages::results')
            ->call('calculate', $program['heat']->id)
            ->assertDispatched('notify', message: 'Calculated Heat transfers.');

        $this->assertSame(
            [$heat1[0]->id, $heat2[0]->id],
            $program['dash']->entries()->orderBy('start_position')->pluck('competitor_id')->all(),
        );
        $this->assertSame(
            [$program['heat1']->id, $program['heat2']->id],
            $program['dash']->entries()->orderBy('start_position')->pluck('source_race_id')->all(),
        );

        $featureSeats = $program['feature']->entries()->orderBy('start_position')->get();
        $this->assertSame([6, 7], $featureSeats->pluck('start_position')->all());
        $this->assertSame([$heat1[1]->id, $heat2[1]->id], $featureSeats->pluck('competitor_id')->all());

        $this->assertSame(
            [$heat1[2]->id, $heat1[4]->id, $heat2[3]->id],
            $program['bMain1']->entries()->orderBy('start_position')->pluck('competitor_id')->all(),
        );
        $this->assertSame(
            [$heat1[3]->id, $heat2[2]->id, $heat2[4]->id],
            $program['bMain2']->entries()->orderBy('start_position')->pluck('competitor_id')->all(),
        );
    }

    public function test_recalculating_heat_leaves_b_main_transfers_on_the_feature(): void
    {
        $program = $this->heatTransferProgram();

        Livewire::test('pages::results')->call('calculate', $program['heat']->id);

        $fromBMain = Competitor::factory()->create(['last_name' => 'FromBMain']);
        $program['feature']->placeTransfer($fromBMain->id, 1, $program['bMain1']->id);

        $first = $program['heat1']->entries()->where('competitor_id', $program['heat1Drivers'][0]->id)->first();
        $second = $program['heat1']->entries()->where('competitor_id', $program['heat1Drivers'][1]->id)->first();
        $first->update(['finish_position' => null]);
        $second->update(['finish_position' => 1]);
        $first->update(['finish_position' => 2]);

        Livewire::test('pages::results')->call('calculate', $program['heat']->id);

        $feature = $program['feature']->fresh();
        $this->assertSame(1, $feature->entries()->where('competitor_id', $fromBMain->id)->value('start_position'));
        $this->assertSame($program['bMain1']->id, $feature->entries()->where('competitor_id', $fromBMain->id)->value('source_race_id'));
        $this->assertSame(
            [$program['heat1Drivers'][0]->id, $program['heat2Drivers'][1]->id],
            $feature->entries()->orderBy('start_position')->whereIn('start_position', [6, 7])->pluck('competitor_id')->all(),
        );
        $this->assertSame(
            [$program['heat1Drivers'][1]->id, $program['heat2Drivers'][0]->id],
            $program['dash']->entries()->orderBy('start_position')->pluck('competitor_id')->all(),
        );
    }

    public function test_calculate_errors_when_a_driver_has_no_finish(): void
    {
        $program = $this->heatTransferProgram();
        $program['heat2']->entries()->orderBy('start_position')->first()->update(['finish_position' => null]);

        Livewire::test('pages::results')
            ->call('calculate', $program['heat']->id)
            ->assertDispatched('notify', message: 'Score every driver in Heat 2 before calculating.', type: 'error');

        $this->assertSame(0, $program['dash']->entries()->count());
        $this->assertSame(0, $program['feature']->entries()->count());
        $this->assertSame(0, $program['bMain1']->entries()->count());
        $this->assertSame(0, $program['bMain2']->entries()->count());
    }

    public function test_results_page_shows_the_timing_feed_picker_before_any_snapshots_arrive(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Waiting for timing feed')
            ->assertSee('Apply');
    }

    public function test_results_page_shows_the_live_timing_feed_session(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        TimingFeedResult::factory()->live()->create(['name' => 'Heat 1 Live']);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Live timing')
            ->assertSee('Heat 1 Live')
            ->assertSee('Ann First')
            ->assertSee('Full screen');
    }

    public function test_results_page_lists_timing_feed_snapshots(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        TimingFeedResult::factory()->create(['name' => 'Orbits Heat 1']);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Timing feed')
            ->assertSee('Apply')
            ->assertSee('Orbits Heat 1');
    }

    public function test_results_page_omits_applied_timing_feed_sessions_from_the_picker(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        TimingFeedResult::factory()->applied()->create(['name' => 'Orbits Heat 1']);
        TimingFeedResult::factory()->create(['name' => 'Orbits Heat 2']);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Orbits Heat 2')
            ->assertDontSee('Orbits Heat 1')
            ->assertDontSee('Live timing');
    }

    public function test_results_page_omits_deleted_timing_feed_sessions_from_the_picker(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        TimingFeedResult::factory()->ignored()->create(['name' => 'Practice Junk']);
        TimingFeedResult::factory()->create(['name' => 'Orbits Heat 1']);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Orbits Heat 1')
            ->assertDontSee('Practice Junk')
            ->assertDontSee('Deleted sessions')
            ->assertDontSee('Restore')
            ->assertDontSee('Live timing');
    }

    public function test_results_page_deletes_a_session_from_the_timing_feed_picker(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        $practice = TimingFeedResult::factory()->create(['name' => 'Practice Junk']);
        TimingFeedResult::factory()->create(['name' => 'Orbits Heat 1']);

        Livewire::test('pages::results')
            ->assertSee('Practice Junk')
            ->call('deleteSession', $practice->id)
            ->assertDontSee('Practice Junk')
            ->assertDontSee('Deleted sessions')
            ->assertDontSee('Restore')
            ->assertDispatched('notify', message: 'Deleted Practice Junk.');

        $this->assertNotNull($practice->fresh()->ignored_at);
        $this->get(route('results'))
            ->assertOk()
            ->assertSee('Orbits Heat 1')
            ->assertDontSee('Practice Junk')
            ->assertDontSee('>Practice Junk</option>', false);
    }

    public function test_results_live_board_shows_gap_and_diff_instead_of_elapsed_time(): void
    {
        Setting::put(Setting::TrackLength, '0.250');

        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $race->syncLineup([Competitor::factory()->create()->id]);
        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1 Live',
            'standings' => [
                [
                    'position' => 1,
                    'number' => '12',
                    'transponder' => '12000',
                    'laps' => 5,
                    'total_time' => '00:01:00.000',
                    'first_name' => 'Ann',
                    'last_name' => 'First',
                    'last_lap' => '16.500',
                ],
                [
                    'position' => 2,
                    'number' => '7',
                    'transponder' => '7000',
                    'laps' => 5,
                    'total_time' => '00:01:01.234',
                    'first_name' => 'Bob',
                    'last_name' => 'Second',
                ],
            ],
        ]);

        $this->get(route('results'))
            ->assertOk()
            ->assertSee('>Last</th>', false)
            ->assertSee('>Best</th>', false)
            ->assertSee('Gap')
            ->assertSee('Diff')
            ->assertSee('Speed')
            ->assertSee('54.545')
            ->assertSee('+1.234')
            ->assertSee('bg-zinc-950', false)
            ->assertDontSee('>00:01:00.000<', false);
    }

    public function test_applying_a_feed_result_scores_drivers_by_transponder(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $winner = Competitor::factory()->create(['transponder' => '52474', 'car_number' => '12']);
        $second = Competitor::factory()->create(['transponder' => '11111', 'car_number' => '7']);
        $race->syncLineup([$winner->id, $second->id]);
        $result = TimingFeedResult::factory()->live()->create([
            'name' => 'Orbits Heat 1',
            'standings' => [
                ['position' => 1, 'number' => '12', 'transponder' => '52474'],
                ['position' => 2, 'number' => '7', 'transponder' => '11111'],
            ],
        ]);

        Livewire::test('pages::results')
            ->assertSee('Orbits Heat 1')
            ->set('feedResultByRace.'.$race->id, (string) $result->id)
            ->call('applyFeedResult', $race->id)
            ->assertDispatched('notify', message: 'Applied 2 finishes to Heat 1.')
            ->assertDontSee('Orbits Heat 1')
            ->assertDontSee('Live timing');

        $this->assertSame(1, $race->entries()->where('competitor_id', $winner->id)->first()->finish_position);
        $this->assertSame(2, $race->entries()->where('competitor_id', $second->id)->first()->finish_position);
        $this->assertSame(TimingStatus::Complete, $race->fresh()->timing_status);
        $this->assertNotNull($result->fresh()->applied_at);
        $this->assertFalse($result->fresh()->is_live);
        $this->assertSame($race->id, $result->fresh()->applied_to_race_id);

        $this->get(route('results'))
            ->assertOk()
            ->assertDontSee('Orbits Heat 1')
            ->assertDontSee('Live timing');
    }

    public function test_applying_a_feed_result_falls_back_to_car_number(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $winner = Competitor::factory()->create(['transponder' => '99999', 'car_number' => '12']);
        $race->syncLineup([$winner->id]);
        $result = TimingFeedResult::factory()->create([
            'standings' => [
                ['position' => 1, 'number' => '12', 'transponder' => '52474'],
            ],
        ]);

        Livewire::test('pages::results')
            ->set('feedResultByRace.'.$race->id, (string) $result->id)
            ->call('applyFeedResult', $race->id)
            ->assertHasNoErrors();

        $this->assertSame(1, $race->entries()->where('competitor_id', $winner->id)->first()->finish_position);
    }

    public function test_applying_a_feed_result_does_not_score_when_nobody_matches(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $driver = Competitor::factory()->create(['transponder' => '1', 'car_number' => '1']);
        $race->syncLineup([$driver->id]);
        $result = TimingFeedResult::factory()->create();

        Livewire::test('pages::results')
            ->set('feedResultByRace.'.$race->id, (string) $result->id)
            ->call('applyFeedResult', $race->id)
            ->assertDispatched('notify', message: 'No drivers in Heat 1 matched that feed result.');

        $this->assertNull($race->entries()->where('competitor_id', $driver->id)->first()->finish_position);
        $this->assertNull($result->fresh()->applied_at);
        $this->assertSame(TimingStatus::Pending, $race->fresh()->timing_status);
    }

    public function test_timing_users_cannot_open_results(): void
    {
        $this->actingAs(User::factory()->withAreas(['timing'])->create());

        $this->get(route('results'))->assertForbidden();
    }

    /**
     * Heat 1–2 with five finishers, Dash, Feature (12 spots), two B-Mains, and Heat transfer rules.
     *
     * @return array{
     *     heat: RaceType,
     *     dash: Race,
     *     feature: Race,
     *     bMain1: Race,
     *     bMain2: Race,
     *     heat1: Race,
     *     heat2: Race,
     *     heat1Drivers: Collection<int, Competitor>,
     *     heat2Drivers: Collection<int, Competitor>
     * }
     */
    private function heatTransferProgram(bool $score = true): array
    {
        $heat = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true, 'sort_order' => 1]);
        $dashType = RaceType::factory()->create(['name' => 'Dash', 'sort_order' => 2]);
        $bMainType = RaceType::factory()->create(['name' => 'B-Main', 'sort_order' => 3]);
        $featureType = RaceType::factory()->create(['name' => 'Feature', 'sort_order' => 4]);

        RaceTypeTransferRule::factory()->for($heat)->create([
            'position_from' => 1,
            'position_to' => 1,
            'destination_race_type_id' => $dashType->id,
            'distribute' => RaceTypeTransferRule::DistributeAppend,
            'sort_order' => 1,
        ]);
        RaceTypeTransferRule::factory()->for($heat)->create([
            'position_from' => 2,
            'position_to' => 2,
            'destination_race_type_id' => $featureType->id,
            'distribute' => RaceTypeTransferRule::DistributeAppend,
            'destination_start_slot' => 6,
            'sort_order' => 2,
        ]);
        RaceTypeTransferRule::factory()->for($heat)->create([
            'position_from' => 3,
            'position_to' => 5,
            'destination_race_type_id' => $bMainType->id,
            'distribute' => RaceTypeTransferRule::DistributeStagger,
            'sort_order' => 3,
        ]);

        $heat1 = Race::factory()->for($heat)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        $heat2 = Race::factory()->for($heat)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        $dash = Race::factory()->for($dashType)->create(['name' => 'Dash']);
        $feature = Race::factory()->for($featureType)->create(['name' => 'Feature', 'starting_positions' => 12]);
        $bMain1 = Race::factory()->for($bMainType)->create(['name' => 'B-Main 1', 'sort_order' => 1]);
        $bMain2 = Race::factory()->for($bMainType)->create(['name' => 'B-Main 2', 'sort_order' => 2]);

        $heat1Drivers = Competitor::factory()->count(5)->create();
        $heat2Drivers = Competitor::factory()->count(5)->create();
        $heat1->syncLineup($heat1Drivers->pluck('id')->all());
        $heat2->syncLineup($heat2Drivers->pluck('id')->all());

        if ($score) {
            $this->scoreStartOrder($heat1);
            $this->scoreStartOrder($heat2);
        }

        return [
            'heat' => $heat,
            'dash' => $dash,
            'feature' => $feature,
            'bMain1' => $bMain1,
            'bMain2' => $bMain2,
            'heat1' => $heat1,
            'heat2' => $heat2,
            'heat1Drivers' => $heat1Drivers,
            'heat2Drivers' => $heat2Drivers,
        ];
    }

    private function scoreStartOrder(Race $race): void
    {
        $race->unsetRelation('entries');

        foreach ($race->entries as $index => $entry) {
            $entry->update(['finish_position' => $index + 1]);
        }
    }
}
