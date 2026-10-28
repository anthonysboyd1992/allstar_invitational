<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LineupBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_page_renders(): void
    {
        Race::factory()->create(['name' => 'Heat 1']);

        $this->get(route('lineups'))
            ->assertOk()
            ->assertSee('Heat 1')
            ->assertSee('Assign by pill')
            ->assertSee('min-h-0 flex-1 overflow-y-auto', false)
            ->assertSee('sticky top-0 z-20 bg-slate-100', false)
            ->assertDontSee('Assign by weight')
            ->assertDontSee('toggleAssignsByPill');
    }

    public function test_lineup_cards_show_a_drivers_pill_number_when_the_type_option_is_on(): void
    {
        $type = RaceType::factory()->create(['assigns_by_pill' => true]);
        $race = Race::factory()->for($type)->create();
        $driver = Competitor::factory()->create([
            'pill_number' => 17,
            'first_name' => 'Ann',
            'last_name' => 'One',
        ]);
        $race->syncLineup([$driver->id]);

        $this->get(route('lineups'))->assertOk()->assertSee('P17');
    }

    public function test_lineup_cards_hide_pill_when_the_type_option_is_off(): void
    {
        $type = RaceType::factory()->create(['assigns_by_pill' => false]);
        $race = Race::factory()->for($type)->create();
        $driver = Competitor::factory()->create(['pill_number' => 17]);
        $race->syncLineup([$driver->id]);

        $this->get(route('lineups'))->assertOk()->assertDontSee('P17');
    }

    public function test_editing_a_race_type_can_turn_on_show_pill(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'assigns_by_pill' => false]);

        Livewire::test('pages::lineups')
            ->call('openRaceTypeEditor', $type->id)
            ->assertSet('typeForm.name', 'Heat')
            ->assertSet('typeForm.shows_pill', false)
            ->set('typeForm.shows_pill', true)
            ->call('saveRaceType')
            ->assertHasNoErrors()
            ->assertSet('editingRaceTypeId', null);

        $this->assertTrue($type->refresh()->assigns_by_pill);
    }

    public function test_editing_a_race_type_saves_results_and_transfer_rules(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat', 'has_results' => false]);
        $dash = RaceType::factory()->create(['name' => 'Dash']);

        Livewire::test('pages::lineups')
            ->call('openRaceTypeEditor', $heat->id)
            ->assertSet('typeForm.has_results', false)
            ->set('typeForm.has_results', true)
            ->call('addTransferRule')
            ->set('transferRules.0.position_from', '1')
            ->set('transferRules.0.position_to', '1')
            ->set('transferRules.0.destination_race_type_id', (string) $dash->id)
            ->set('transferRules.0.distribute', RaceTypeTransferRule::DistributeStagger)
            ->set('transferRules.0.destination_start_slot', '6')
            ->call('saveRaceType')
            ->assertHasNoErrors()
            ->assertSet('editingRaceTypeId', null);

        $heat->refresh();
        $this->assertTrue($heat->has_results);

        $rule = $heat->transferRules()->sole();
        $this->assertSame(1, $rule->position_from);
        $this->assertSame(1, $rule->position_to);
        $this->assertSame($dash->id, $rule->destination_race_type_id);
        $this->assertSame(RaceTypeTransferRule::DistributeStagger, $rule->distribute);
        $this->assertSame(6, $rule->destination_start_slot);
    }

    public function test_adding_a_race_type_creates_numbered_races(): void
    {
        Livewire::test('pages::lineups')
            ->set('newTypeName', 'Heat')
            ->set('newTypeRaceCount', 3)
            ->call('addRaceType')
            ->assertHasNoErrors()
            ->set('newTypeName', 'Hot Laps')
            ->set('newTypeRaceCount', 1)
            ->call('addRaceType');

        $this->assertSame(['Heat 1', 'Heat 2', 'Heat 3'], RaceType::query()->where('name', 'Heat')->first()->races->pluck('name')->all());
        $this->assertSame(['Hot Laps'], RaceType::query()->where('name', 'Hot Laps')->first()->races->pluck('name')->all());
    }

    public function test_a_heats_type_numbers_races_from_the_race_name(): void
    {
        $component = Livewire::test('pages::lineups')
            ->set('newTypeName', 'Heats')
            ->set('newTypeRaceName', 'Heat')
            ->set('newTypeRaceCount', 2)
            ->call('addRaceType')
            ->assertHasNoErrors();

        $type = RaceType::query()->where('name', 'Heats')->first();
        $this->assertSame('Heat', $type->race_name);
        $this->assertSame(['Heat 1', 'Heat 2'], $type->races->pluck('name')->all());

        $component->call('addRace', $type->id);

        $this->assertSame(['Heat 1', 'Heat 2', 'Heat 3'], $type->refresh()->races->pluck('name')->all());
    }

    public function test_editing_a_race_type_saves_the_race_name(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);

        Livewire::test('pages::lineups')
            ->call('openRaceTypeEditor', $type->id)
            ->set('typeForm.name', 'Heats')
            ->set('typeForm.race_name', 'Heat')
            ->call('saveRaceType')
            ->assertHasNoErrors();

        $type->refresh();
        $this->assertSame('Heats', $type->name);
        $this->assertSame('Heat', $type->race_name);
    }

    public function test_dropping_roster_drivers_inserts_them_at_the_drop_position(): void
    {
        $race = Race::factory()->create();
        [$first, $second, $third] = Competitor::factory()->count(3)->create();

        Livewire::test('pages::lineups')
            ->call('placeDriver', 'c'.$first->id, 0, (string) $race->id)
            ->call('placeDriver', 'c'.$second->id, 1, (string) $race->id)
            ->call('placeDriver', 'c'.$third->id, 0, (string) $race->id);

        $this->assertSame([$third->id, $first->id, $second->id], $race->lineupIds());
        $this->assertSame([1, 2, 3], $race->entries()->pluck('start_position')->all());
    }

    public function test_the_same_driver_can_be_in_different_races_but_not_twice_in_one(): void
    {
        $heat = Race::factory()->create();
        $feature = Race::factory()->create();
        $driver = Competitor::factory()->create();

        Livewire::test('pages::lineups')
            ->call('placeDriver', 'c'.$driver->id, 0, $heat->id)
            ->call('placeDriver', 'c'.$driver->id, 0, $feature->id)
            ->call('placeDriver', 'c'.$driver->id, 1, $heat->id)
            ->assertDispatched('notify');

        $this->assertSame([$driver->id], $heat->lineupIds());
        $this->assertSame([$driver->id], $feature->lineupIds());
    }

    public function test_reordering_within_a_race(): void
    {
        $race = Race::factory()->create();
        $ids = Competitor::factory()->count(3)->create()->pluck('id')->all();
        $race->syncLineup($ids);
        $lastEntry = $race->entries()->where('competitor_id', $ids[2])->first();

        Livewire::test('pages::lineups')->call('placeDriver', 'e'.$lastEntry->id, 0, $race->id);

        $this->assertSame([$ids[2], $ids[0], $ids[1]], $race->lineupIds());
    }

    public function test_dragging_between_races_moves_the_driver(): void
    {
        $from = Race::factory()->create();
        $to = Race::factory()->create();
        [$moving, $staying] = Competitor::factory()->count(2)->create();
        $from->syncLineup([$moving->id, $staying->id]);
        $entry = $from->entries()->where('competitor_id', $moving->id)->first();

        Livewire::test('pages::lineups')->call('placeDriver', 'e'.$entry->id, 0, $to->id);

        $this->assertSame([$staying->id], $from->lineupIds());
        $this->assertSame(1, $from->entries()->first()->start_position);
        $this->assertSame([$moving->id], $to->lineupIds());
    }

    public function test_removing_a_driver_renumbers_the_lineup(): void
    {
        $race = Race::factory()->create();
        $ids = Competitor::factory()->count(3)->create()->pluck('id')->all();
        $race->syncLineup($ids);

        Livewire::test('pages::lineups')->call('removeFromRace', $race->id, $ids[0]);

        $this->assertSame([$ids[1], $ids[2]], $race->lineupIds());
        $this->assertSame([1, 2], $race->entries()->pluck('start_position')->all());
    }

    public function test_the_lineup_board_does_not_make_a_race_live(): void
    {
        $race = Race::factory()->create();

        Livewire::test('pages::lineups')
            ->assertDontSee('goLive('.$race->id.')', false)
            ->assertSee('Pick a live race on the vMix page');

        $this->assertNull(Setting::get(Setting::ActiveRace));
    }

    public function test_clicking_drivers_appends_them_to_the_live_race(): void
    {
        $heat = Race::factory()->create();
        $feature = Race::factory()->create();
        [$first, $second] = Competitor::factory()->count(2)->create();
        Setting::put(Setting::ActiveRace, (string) $heat->id);

        Livewire::test('pages::lineups')
            ->call('addToLiveRace', $first->id)
            ->call('addToLiveRace', $second->id)
            ->call('addToLiveRace', $first->id)
            ->assertDispatched('notify');

        Setting::put(Setting::ActiveRace, (string) $feature->id);

        Livewire::test('pages::lineups')->call('addToLiveRace', $first->id);

        $this->assertSame([$first->id, $second->id], $heat->lineupIds());
        $this->assertSame([$first->id], $feature->lineupIds());
    }

    public function test_drivers_in_the_live_race_drop_to_the_bottom_of_the_roster(): void
    {
        $heat = Race::factory()->create();
        $feature = Race::factory()->create();
        $one = Competitor::factory()->create(['car_number' => '1']);
        $twoB = Competitor::factory()->create(['car_number' => '2B']);
        $eleven = Competitor::factory()->create(['car_number' => '11']);
        Setting::put(Setting::ActiveRace, (string) $heat->id);

        $component = Livewire::test('pages::lineups')
            ->call('addToLiveRace', $one->id);

        $this->assertSame([$twoB->id, $eleven->id, $one->id], $component->get('roster')->pluck('id')->all());

        $component->call('addToLiveRace', $eleven->id);
        $this->assertSame([$twoB->id, $one->id, $eleven->id], $component->get('roster')->pluck('id')->all());

        Setting::put(Setting::ActiveRace, (string) $feature->id);
        $component->call('$refresh');
        $this->assertSame([$one->id, $twoB->id, $eleven->id], $component->get('roster')->pluck('id')->all());
    }

    public function test_clicking_a_driver_without_a_live_race_shows_an_error(): void
    {
        $driver = Competitor::factory()->create();

        Livewire::test('pages::lineups')
            ->call('addToLiveRace', $driver->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertDatabaseCount('race_entries', 0);
    }

    public function test_typing_a_car_number_adds_that_driver_to_that_race(): void
    {
        $heat = Race::factory()->create(['name' => 'Heat 1']);
        $feature = Race::factory()->create(['name' => 'Feature']);
        $driver = Competitor::factory()->create(['car_number' => '47', 'first_name' => 'Collin', 'last_name' => 'Thirlby']);

        Livewire::test('pages::lineups')
            ->set('carNumberInputs.'.$heat->id, '#47')
            ->call('addByCarNumber', $heat->id)
            ->assertHasNoErrors()
            ->assertSet('carNumberInputs.'.$heat->id, '');

        $this->assertSame([$driver->id], $heat->fresh()->lineupIds());
        $this->assertSame([], $feature->fresh()->lineupIds());
    }

    public function test_unknown_car_number_does_not_add_a_driver(): void
    {
        $race = Race::factory()->create();

        Livewire::test('pages::lineups')
            ->set('carNumberInputs.'.$race->id, '99')
            ->call('addByCarNumber', $race->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertDatabaseCount('race_entries', 0);
    }

    public function test_car_number_lookup_matches_regardless_of_case(): void
    {
        $race = Race::factory()->create();
        $driver = Competitor::factory()->create(['car_number' => '5G']);

        Livewire::test('pages::lineups')
            ->set('carNumberInputs.'.$race->id, '5g')
            ->call('addByCarNumber', $race->id)
            ->assertHasNoErrors();

        $this->assertSame([$driver->id], $race->fresh()->lineupIds());
    }

    public function test_typing_a_car_number_fills_the_first_empty_spot_of_a_fixed_field(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        $rear = Competitor::factory()->create(['car_number' => '2']);
        $front = Competitor::factory()->create(['car_number' => '1']);
        $race->placeInSlot($rear->id, 10);

        Livewire::test('pages::lineups')
            ->set('carNumberInputs.'.$race->id, '1')
            ->call('addByCarNumber', $race->id)
            ->assertHasNoErrors();

        $this->assertSame(
            [1, 10],
            $race->fresh()->entries()->pluck('start_position')->all(),
        );
    }

    public function test_a_duplicate_car_number_does_not_add_a_driver(): void
    {
        $race = Race::factory()->create();
        Competitor::factory()->count(2)->create(['car_number' => '7']);

        Livewire::test('pages::lineups')
            ->set('carNumberInputs.'.$race->id, '7')
            ->call('addByCarNumber', $race->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertDatabaseCount('race_entries', 0);
    }

    public function test_race_types_can_be_reordered(): void
    {
        $hotLaps = RaceType::factory()->create(['name' => 'Hot Laps', 'sort_order' => 1]);
        $heat = RaceType::factory()->create(['name' => 'Heat', 'sort_order' => 2]);
        $main = RaceType::factory()->create(['name' => 'A-Main', 'sort_order' => 3]);

        Livewire::test('pages::lineups')
            ->call('moveRaceType', $main->id, -1)
            ->call('moveRaceType', $hotLaps->id, -1);

        $this->assertSame(
            [$hotLaps->id, $main->id, $heat->id],
            RaceType::query()->orderBy('sort_order')->pluck('id')->all(),
        );
    }

    public function test_edit_modal_saves_race_details(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1', 'laps' => 8]);
        $mains = RaceType::factory()->create(['name' => 'A-Main']);

        Livewire::test('pages::lineups')
            ->call('openRaceEditor', $race->id)
            ->assertSet('raceForm.name', 'Heat 1')
            ->assertSet('raceForm.laps', '8')
            ->set('raceForm.name', 'A-Main')
            ->set('raceForm.race_type_id', (string) $mains->id)
            ->set('raceForm.laps', '25')
            ->set('raceForm.transfers', 'Top 3 transfer')
            ->set('raceForm.starting_positions', '12')
            ->set('raceForm.sponsor', 'Casey’s')
            ->set('raceForm.lineup_name', 'IMCA Modified Heat 1')
            ->call('saveRace')
            ->assertHasNoErrors()
            ->assertSet('editingRaceId', null);

        $race->refresh();
        $this->assertSame('A-Main', $race->name);
        $this->assertSame($mains->id, $race->race_type_id);
        $this->assertSame(25, $race->laps);
        $this->assertSame('Top 3 transfer', $race->transfers);
        $this->assertSame(12, $race->starting_positions);
        $this->assertSame('Casey’s', $race->sponsor);
        $this->assertSame('IMCA Modified Heat 1', $race->lineup_name);
    }

    public function test_edit_modal_validates_and_clears_blank_fields(): void
    {
        $race = Race::factory()->create(['laps' => 8, 'sponsor' => 'Old Sponsor']);

        Livewire::test('pages::lineups')
            ->call('openRaceEditor', $race->id)
            ->set('raceForm.name', '')
            ->set('raceForm.laps', 'abc')
            ->call('saveRace')
            ->assertHasErrors(['raceForm.name', 'raceForm.laps'])
            ->set('raceForm.name', 'Heat 1')
            ->set('raceForm.laps', '')
            ->set('raceForm.sponsor', '  ')
            ->call('saveRace')
            ->assertHasNoErrors();

        $race->refresh();
        $this->assertNull($race->laps);
        $this->assertNull($race->sponsor);
    }

    public function test_a_fixed_field_can_fill_tenth_without_the_front_row(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        $driver = Competitor::factory()->create();

        Livewire::test('pages::lineups')->call('placeDriver', 'c'.$driver->id, 0, $race->id.'_10');

        $this->assertSame([$driver->id], $race->fresh()->lineupIds());
        $this->assertSame(10, $race->entries()->first()->start_position);
        $this->assertSame(1, $race->entries()->count());
    }

    public function test_removing_from_a_fixed_field_leaves_the_later_spot(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        [$front, $rear] = Competitor::factory()->count(2)->create();
        $race->placeInSlot($front->id, 6);
        $race->placeInSlot($rear->id, 10);

        Livewire::test('pages::lineups')->call('removeFromRace', $race->id, $front->id);

        $this->assertSame([$rear->id], $race->fresh()->lineupIds());
        $this->assertSame(10, $race->entries()->first()->start_position);
    }

    public function test_clicking_a_driver_fills_the_first_empty_spot_of_a_fixed_field(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        $front = Competitor::factory()->create();
        $rear = Competitor::factory()->create();
        $race->placeInSlot($rear->id, 10);
        Setting::put(Setting::ActiveRace, (string) $race->id);

        Livewire::test('pages::lineups')->call('addToLiveRace', $front->id);

        $this->assertSame(
            [1, 10],
            $race->fresh()->entries()->pluck('start_position')->all(),
        );
    }

    public function test_a_full_fixed_field_rejects_another_starter(): void
    {
        $race = Race::factory()->create(['starting_positions' => 2]);
        [$first, $second, $third] = Competitor::factory()->count(3)->create();
        $race->placeInSlot($first->id, 1);
        $race->placeInSlot($second->id, 2);
        Setting::put(Setting::ActiveRace, (string) $race->id);

        Livewire::test('pages::lineups')
            ->call('addToLiveRace', $third->id)
            ->assertDispatched('notify', type: 'error');

        $this->assertSame(2, $race->fresh()->entries()->count());
    }

    public function test_dropping_on_an_occupied_spot_from_the_roster_is_rejected(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        [$occupant, $incoming] = Competitor::factory()->count(2)->create();
        $race->placeInSlot($occupant->id, 10);

        Livewire::test('pages::lineups')
            ->call('placeDriver', 'c'.$incoming->id, 0, $race->id.'_10')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame([$occupant->id], $race->fresh()->lineupIds());
    }

    public function test_reordering_inside_a_fixed_field_swaps_spots(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        [$inside, $outside] = Competitor::factory()->count(2)->create();
        $race->placeInSlot($inside->id, 6);
        $race->placeInSlot($outside->id, 10);
        $entry = $race->entries()->where('competitor_id', $outside->id)->first();

        Livewire::test('pages::lineups')->call('placeDriver', 'e'.$entry->id, 0, $race->id.'_6');

        $this->assertSame(
            [6 => $outside->id, 10 => $inside->id],
            $race->fresh()->entries()->pluck('competitor_id', 'start_position')->all(),
        );
    }

    public function test_clearing_starting_positions_packs_the_lineup_from_the_front(): void
    {
        $race = Race::factory()->create(['starting_positions' => 10]);
        [$first, $second] = Competitor::factory()->count(2)->create();
        $race->placeInSlot($first->id, 6);
        $race->placeInSlot($second->id, 10);

        Livewire::test('pages::lineups')->call('updateStartingPositions', $race->id, '');

        $this->assertNull($race->fresh()->starting_positions);
        $this->assertSame([$first->id, $second->id], $race->lineupIds());
        $this->assertSame([1, 2], $race->entries()->pluck('start_position')->all());
    }
}
