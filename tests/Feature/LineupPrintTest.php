<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LineupPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_printed_lineup_lists_starting_order_hometown_and_kart(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $race = Race::factory()->for($type)->create([
            'name' => 'Heat Race 1',
            'sponsor' => 'Move Over Michigan Dirt Late Model Series',
        ]);
        $fast = Car::factory()->create(['number' => '1', 'time_ms' => 10000]);
        $slow = Car::factory()->create(['number' => '5', 'time_ms' => 12000]);
        $type->cars()->attach($fast->id, ['sort_order' => 0]);
        $type->cars()->attach($slow->id, ['sort_order' => 1]);

        $heavy = Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
            'hometown' => 'Traverse City, MI',
            'weight' => 210.0,
            'accolades' => '2025 WoodTic Champion',
        ]);
        $light = Competitor::factory()->create([
            'car_number' => '45',
            'first_name' => 'Conner',
            'last_name' => 'Zbozien',
            'hometown' => 'St. Clair Shores, MI',
            'weight' => 160.0,
            'accolades' => '2024 Flat Rock Speedway Track Champion',
        ]);

        $race->syncLineup([$light->id, $heavy->id]);

        $this->get(route('lineups.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('MOVE OVER MICHIGAN DIRT LATE MODEL SERIES - HEAT RACE 1')
            ->assertSee('Pos')
            ->assertSee('Kart Assignment')
            ->assertSee('.pill, .kart { text-align: center', false)
            ->assertSee('Conner Zbozien')
            ->assertSee('St. Clair Shores, MI')
            ->assertSee('2024 Flat Rock Speedway Track Champion')
            ->assertSee('class="kart">5</td>', false)
            ->assertSee('Collin Thirlby')
            ->assertSee('class="kart">1</td>', false)
            ->assertDontSee('class="weight"', false)
            ->assertDontSee('>Weight</th>', false)
            ->assertDontSee('SLOWEST')
            ->assertDontSee('FAST')
            ->assertSee('images/letterhead-left.png', false)
            ->assertSee('images/letterhead-right.png', false)
            ->assertSee('letterhead-left')
            ->assertSee('1.25in')
            ->assertSee('letterhead-right')
            ->assertSee('.letterhead-right { height: 0.7in; max-width: 2.4in;', false)
            ->assertSee('class="letterhead-kind">Lineups</p>', false)
            ->assertSee('letterhead-center')
            ->assertSee('white-space: nowrap')
            ->assertSee('text-align: center')
            ->assertSee('letter landscape')
            ->assertSee('11in')
            ->assertSee('class="accolades"', false)
            ->assertSee('sheet-pages')
            ->assertSee('table-header-group')
            ->assertSee('print-color-adjust: exact')
            ->assertSee('#ee6426')
            ->assertSee('.cols th { background: #111')
            ->assertSee('race-block')
            ->assertSee('page-break-inside: avoid')
            ->assertDontSee('Heat Race 2');
    }

    public function test_printed_lineup_uses_the_lineup_override_name_without_sponsor(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heats']);
        $race = Race::factory()->for($type)->create([
            'name' => 'Heat 1',
            'lineup_name' => 'IMCA Modified Heat 1',
            'sponsor' => 'ACME',
        ]);

        $this->get(route('lineups.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('>IMCA MODIFIED HEAT 1</th>', false)
            ->assertDontSee('ACME')
            ->assertDontSee('>HEAT 1</th>', false);
    }

    public function test_print_without_a_selection_includes_every_race(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat', 'sort_order' => 1]);
        $feature = RaceType::factory()->create(['name' => 'Feature', 'sort_order' => 2]);
        Race::factory()->for($heat)->create(['name' => 'Heat 1']);
        Race::factory()->for($feature)->create(['name' => 'Feature']);

        $this->get(route('lineups.print'))
            ->assertOk()
            ->assertSee('HEAT 1')
            ->assertSee('FEATURE')
            ->assertDontSee('PUT SPONSOR HERE');
    }

    public function test_printed_heats_appear_in_numbered_order(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat', 'sort_order' => 1]);
        $feature = RaceType::factory()->create(['name' => 'Feature', 'sort_order' => 2]);
        Race::factory()->for($heat)->create(['name' => 'Heat 5', 'sort_order' => 1]);
        Race::factory()->for($heat)->create(['name' => 'Heat 10', 'sort_order' => 2]);
        Race::factory()->for($heat)->create(['name' => 'Heat 2', 'sort_order' => 3]);
        Race::factory()->for($heat)->create(['name' => 'Heat 1', 'sort_order' => 4]);
        Race::factory()->for($feature)->create(['name' => 'Feature', 'sort_order' => 1]);

        $this->get(route('lineups.print'))
            ->assertOk()
            ->assertSeeInOrder([
                '>HEAT 1</th>',
                '>HEAT 2</th>',
                '>HEAT 5</th>',
                '>HEAT 10</th>',
                '>FEATURE</th>',
            ], false);
    }

    public function test_print_picker_lists_heats_in_numbered_order(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat']);
        Race::factory()->for($heat)->create(['name' => 'Heat 5', 'sort_order' => 1]);
        Race::factory()->for($heat)->create(['name' => 'Heat 1', 'sort_order' => 2]);
        Race::factory()->for($heat)->create(['name' => 'Heat 2', 'sort_order' => 3]);

        Livewire::test('pages::lineups')
            ->call('openPrintPicker', $heat->id)
            ->assertSeeInOrder(['Heat 1', 'Heat 2', 'Heat 5']);
    }

    public function test_printed_lineups_use_uploaded_letterhead_logos(): void
    {
        Storage::fake('public');
        Setting::replaceLetterhead('left', UploadedFile::fake()->image('left.png', 80, 40));
        Setting::replaceLetterhead('right', UploadedFile::fake()->image('right.png', 80, 40));

        $this->get(route('lineups.print'))
            ->assertOk()
            ->assertSee(MediaStorage::url(Setting::letterheadPath('left')), false)
            ->assertSee(MediaStorage::url(Setting::letterheadPath('right')), false)
            ->assertDontSee('images/letterhead-left.png', false);
    }

    public function test_print_only_includes_the_selected_races(): void
    {
        $type = RaceType::factory()->create();
        $first = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);

        $this->get(route('lineups.print', ['races' => [$first->id]]))
            ->assertOk()
            ->assertSee('HEAT 1')
            ->assertDontSee('HEAT 2');
    }

    public function test_unknown_race_ids_are_rejected(): void
    {
        $this->get(route('lineups.print', ['races' => [999]]))
            ->assertSessionHasErrors('races.0');
    }

    public function test_print_picker_preselects_races_in_the_current_type(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat']);
        $feature = RaceType::factory()->create(['name' => 'Feature']);
        $heatRace = Race::factory()->for($heat)->create();
        Race::factory()->for($feature)->create();

        Livewire::test('pages::lineups')
            ->call('openPrintPicker', $heat->id)
            ->assertSet('printPickerOpen', true)
            ->assertSet('printRaceIds', [(string) $heatRace->id])
            ->assertSee('Print selected');
    }

    public function test_choosing_a_race_enables_print_with_only_that_race_in_the_url(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat']);
        $feature = RaceType::factory()->create(['name' => 'Feature']);
        $heat1 = Race::factory()->for($heat)->create(['name' => 'Heat 1']);
        $heat2 = Race::factory()->for($heat)->create(['name' => 'Heat 2']);
        Race::factory()->for($feature)->create(['name' => 'Feature']);

        $url = route('lineups.print', ['races' => [$heat1->id]]);

        Livewire::test('pages::lineups')
            ->call('openPrintPicker')
            ->call('clearPrintRaces')
            ->assertSet('printRaceIds', [])
            ->assertSee('pointer-events-none', false)
            ->assertDontSee($url, false)
            ->set('printRaceIds', [(string) $heat1->id])
            ->assertDontSee('pointer-events-none', false)
            ->assertSee($url, false);

        $this->get($url)
            ->assertOk()
            ->assertSee('HEAT 1')
            ->assertDontSee('HEAT 2')
            ->assertDontSee('FEATURE');
    }

    public function test_printed_lineup_includes_pill_before_kart_when_the_type_option_is_on(): void
    {
        $type = RaceType::factory()->create(['assigns_by_pill' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1']);
        $driver = Competitor::factory()->create([
            'first_name' => 'Ann',
            'last_name' => 'One',
            'pill_number' => 17,
        ]);
        $race->syncLineup([$driver->id]);

        $this->get(route('lineups.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSeeInOrder([
                'class="accolades">Accolades</th>',
                'class="pill">Pill</th>',
                'class="kart">Kart Assignment</th>',
                'Ann One',
                'class="pill">17</td>',
            ], false)
            ->assertSee('colspan="7"', false)
            ->assertDontSee('class="weight"', false);
    }

    public function test_printed_lineup_omits_pill_when_the_type_option_is_off(): void
    {
        $type = RaceType::factory()->create(['assigns_by_pill' => false]);
        $race = Race::factory()->for($type)->create(['name' => 'Feature']);
        $driver = Competitor::factory()->create([
            'first_name' => 'Ann',
            'last_name' => 'One',
            'pill_number' => 17,
        ]);
        $race->syncLineup([$driver->id]);

        $this->get(route('lineups.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('Ann One')
            ->assertSee('class="kart">Kart Assignment</th>', false)
            ->assertDontSee('class="pill"', false)
            ->assertDontSee('class="weight"', false);
    }

    public function test_a_fixed_field_prints_empty_starting_spots(): void
    {
        $type = RaceType::factory()->create(['name' => 'Feature']);
        $race = Race::factory()->for($type)->create(['name' => 'Feature', 'starting_positions' => 10]);
        $driver = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'One']);
        $race->placeInSlot($driver->id, 10);

        $this->get(route('lineups.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSeeInOrder([
                'class="pos">1</td>',
                'class="pos">6</td>',
                'class="pos">10</td>',
                'Ann One',
            ], false);
    }

    public function test_each_race_prints_as_its_own_group_even_when_timing_is_one_file(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'combines_groups' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Hotlaps 1', 'sponsor' => 'ACME']);
        $second = Race::factory()->for($type)->create(['name' => 'Hotlaps 2', 'sponsor' => 'ACME']);
        $drivers = Competitor::factory()->count(4)->sequence(
            ['first_name' => 'Ann', 'last_name' => 'One'],
            ['first_name' => 'Bob', 'last_name' => 'Two'],
            ['first_name' => 'Cara', 'last_name' => 'Three'],
            ['first_name' => 'Dan', 'last_name' => 'Four'],
        )->create();
        $first->syncLineup([$drivers[0]->id, $drivers[1]->id]);
        $second->syncLineup([$drivers[2]->id, $drivers[3]->id]);

        $this->get(route('lineups.print', ['races' => [$first->id, $second->id]]))
            ->assertOk()
            ->assertSeeInOrder([
                '>ACME - HOTLAPS 1</th>',
                'Ann One',
                'Bob Two',
                '>ACME - HOTLAPS 2</th>',
                'Cara Three',
                'Dan Four',
            ], false)
            ->assertDontSee('GROUP 1')
            ->assertDontSee('GROUP 2');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('lineups.print'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_lineups(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('lineups.print'))->assertForbidden();
    }
}
