<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\User;
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
