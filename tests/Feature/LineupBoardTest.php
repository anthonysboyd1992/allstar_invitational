<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
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

        $this->get(route('lineups'))->assertOk()->assertSee('Heat 1');
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

    public function test_clicking_a_race_makes_it_live_and_clicking_again_keeps_it_live(): void
    {
        $race = Race::factory()->create();

        $component = Livewire::test('pages::lineups')->call('goLive', $race->id);
        $this->assertSame((string) $race->id, Setting::get(Setting::ActiveRace));

        $component->call('goLive', $race->id);
        $this->assertSame((string) $race->id, Setting::get(Setting::ActiveRace));
    }

    public function test_clicking_drivers_appends_them_to_the_live_race(): void
    {
        $heat = Race::factory()->create();
        $feature = Race::factory()->create();
        [$first, $second] = Competitor::factory()->count(2)->create();

        Livewire::test('pages::lineups')
            ->call('goLive', $heat->id)
            ->call('addToLiveRace', $first->id)
            ->call('addToLiveRace', $second->id)
            ->call('addToLiveRace', $first->id)
            ->assertDispatched('notify')
            ->call('goLive', $feature->id)
            ->call('addToLiveRace', $first->id);

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

        $component = Livewire::test('pages::lineups')
            ->call('goLive', $heat->id)
            ->call('addToLiveRace', $one->id);

        $this->assertSame([$twoB->id, $eleven->id, $one->id], $component->get('roster')->pluck('id')->all());

        $component->call('addToLiveRace', $eleven->id);
        $this->assertSame([$twoB->id, $one->id, $eleven->id], $component->get('roster')->pluck('id')->all());

        $component->call('goLive', $feature->id);
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
            ->set('raceForm.sponsor', 'Casey’s')
            ->call('saveRace')
            ->assertHasNoErrors()
            ->assertSet('editingRaceId', null);

        $race->refresh();
        $this->assertSame('A-Main', $race->name);
        $this->assertSame($mains->id, $race->race_type_id);
        $this->assertSame(25, $race->laps);
        $this->assertSame('Top 3 transfer', $race->transfers);
        $this->assertSame('Casey’s', $race->sponsor);
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
}
