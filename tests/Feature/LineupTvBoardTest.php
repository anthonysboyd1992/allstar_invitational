<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LineupTvBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('lineup-board'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_open_the_lineup_board(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('lineup-board'))->assertForbidden();
    }

    public function test_saving_races_pushes_them_to_the_shared_tv_board(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $heatOne = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        $heatTwo = Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        $ann = Competitor::factory()->create(['car_number' => '11', 'first_name' => 'Ann', 'last_name' => 'First']);
        $bob = Competitor::factory()->create(['car_number' => '22', 'first_name' => 'Bob', 'last_name' => 'Second']);
        $heatOne->syncLineup([$ann->id]);
        $heatTwo->syncLineup([$bob->id]);

        Livewire::test('pages::lineup-board')
            ->assertSee('Nothing on the TV yet')
            ->set('raceIds', [(string) $heatOne->id, (string) $heatTwo->id])
            ->assertSee('unsaved')
            ->assertDontSee('Ann First')
            ->call('saveToTv')
            ->assertSee('2 races on TV')
            ->assertSeeInOrder(['Heat 1', 'Inside', 'Outside', 'Ann First', 'Start 1', 'Heat 2', 'Bob Second', 'Start 1'], false)
            ->assertSee('11')
            ->assertSee('22')
            ->assertSee('tv-heat__grid--lanes', false);

        $this->assertSame([$heatOne->id, $heatTwo->id], Setting::lineupBoardRaceIds());
    }

    public function test_select_all_and_clear_update_the_draft_checkboxes(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $heatOne = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        $heatTwo = Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);

        Livewire::test('pages::lineup-board')
            ->call('selectAll')
            ->assertSet('raceIds', [(string) $heatOne->id, (string) $heatTwo->id])
            ->assertSee('2 selected')
            ->call('clearSelection')
            ->assertSet('raceIds', [])
            ->assertSee('0 selected')
            ->call('selectType', $type->id)
            ->assertSet('raceIds', [(string) $heatOne->id, (string) $heatTwo->id])
            ->call('selectType', $type->id)
            ->assertSet('raceIds', []);
    }

    public function test_more_than_two_races_use_a_single_column(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $races = collect(range(1, 3))->map(
            fn (int $number) => Race::factory()->for($type)->create([
                'name' => 'Heat '.$number,
                'sort_order' => $number,
            ]),
        );
        $driver = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'First']);
        $races[0]->syncLineup([$driver->id]);
        Setting::putLineupBoardRaceIds($races->pluck('id')->all());

        Livewire::test('pages::lineup-board')
            ->assertSee('3 races on TV')
            ->assertSee('tv-heat__grid--stack', false)
            ->assertDontSee('>Inside</span>', false);
    }

    public function test_more_than_five_races_wrap_to_a_new_row(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $races = collect(range(1, 6))->map(
            fn (int $number) => Race::factory()->for($type)->create([
                'name' => 'Heat '.$number,
                'sort_order' => $number,
            ]),
        );
        Setting::putLineupBoardRaceIds($races->pluck('id')->all());

        Livewire::test('pages::lineup-board')
            ->assertSee('6 races on TV')
            ->assertSee('repeat(5, minmax(0, 1fr))', false)
            ->assertSee('grid-auto-rows: minmax(0, 1fr)', false);
    }

    public function test_another_session_picks_up_the_saved_tv_selection_on_refresh(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $heatOne = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        $heatTwo = Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        $ann = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'First']);
        $bob = Competitor::factory()->create(['first_name' => 'Bob', 'last_name' => 'Second']);
        $heatOne->syncLineup([$ann->id]);
        $heatTwo->syncLineup([$bob->id]);

        Setting::putLineupBoardRaceIds([$heatOne->id]);

        $tv = Livewire::test('pages::lineup-board')
            ->assertSee('Ann First')
            ->assertDontSee('Bob Second');

        Setting::putLineupBoardRaceIds([$heatTwo->id]);

        $tv->call('refreshBoard')
            ->assertDontSee('Ann First')
            ->assertSee('Bob Second')
            ->assertSee('1 race on TV');
    }

    public function test_deselecting_without_save_leaves_the_tv_unchanged(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $heatOne = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        $heatTwo = Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        $ann = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'First']);
        $bob = Competitor::factory()->create(['first_name' => 'Bob', 'last_name' => 'Second']);
        $heatOne->syncLineup([$ann->id]);
        $heatTwo->syncLineup([$bob->id]);
        Setting::putLineupBoardRaceIds([$heatOne->id, $heatTwo->id]);

        Livewire::test('pages::lineup-board')
            ->assertSee('Ann First')
            ->assertSee('Bob Second')
            ->set('raceIds', [(string) $heatOne->id])
            ->assertSee('unsaved')
            ->assertSee('Ann First')
            ->assertSee('Bob Second')
            ->call('saveToTv')
            ->assertSee('Ann First')
            ->assertDontSee('Bob Second')
            ->assertSee('1 race on TV');

        $this->assertSame([$heatOne->id], Setting::lineupBoardRaceIds());
    }

    public function test_fixed_fields_show_empty_starting_spots(): void
    {
        $race = Race::factory()->create([
            'name' => 'Heat 1',
            'starting_positions' => 4,
        ]);
        $driver = Competitor::factory()->create(['car_number' => '7', 'first_name' => 'Ann', 'last_name' => 'First']);
        $race->placeInSlot($driver->id, 1);
        Setting::putLineupBoardRaceIds([$race->id]);

        Livewire::test('pages::lineup-board')
            ->assertSee('Heat 1')
            ->assertSee('Ann First')
            ->assertSee('Empty')
            ->assertSee('4 spots');
    }

    public function test_board_shows_assigned_invitational_car(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1']);
        $driver = Competitor::factory()->create(['car_number' => '47', 'first_name' => 'Ann', 'last_name' => 'First']);
        $kart = Car::factory()->create(['number' => '5']);
        $race->syncLineup([$driver->id]);
        $race->entries()->where('competitor_id', $driver->id)->update(['car_id' => $kart->id]);
        Setting::putLineupBoardRaceIds([$race->id]);

        Livewire::test('pages::lineup-board')
            ->assertSee('Ann First')
            ->assertSee('47')
            ->assertSee('Car 5');
    }

    public function test_board_does_not_create_or_change_race_entries(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1']);
        $driver = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'First']);
        $race->syncLineup([$driver->id]);
        Setting::putLineupBoardRaceIds([$race->id]);
        $before = RaceEntry::query()->count();

        Livewire::test('pages::lineup-board')
            ->call('refreshBoard')
            ->assertSee('Ann First');

        $this->assertSame($before, RaceEntry::query()->count());
        $this->assertSame([$driver->id], $race->fresh()->lineupIds());
    }

    public function test_lineups_page_links_to_the_tv_board(): void
    {
        RaceType::factory()->create();

        $this->get(route('lineups'))
            ->assertOk()
            ->assertSee('TV board')
            ->assertSee(route('lineup-board'), false);
    }

    public function test_nav_shows_lineup_board_for_lineups_users(): void
    {
        $this->get(route('lineups'))
            ->assertOk()
            ->assertSee('>Lineup Board</a>', false);
    }
}
