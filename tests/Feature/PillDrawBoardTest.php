<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PillDrawBoardTest extends TestCase
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

        $this->get(route('pill-draw'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_open_the_pill_draw_board(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('pill-draw'))->assertForbidden();
    }

    public function test_board_deals_lowest_pill_to_heat_1_then_wraps_across_five_heats(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        collect(range(1, 5))->each(
            fn (int $number) => Race::factory()->for($type)->create([
                'name' => 'Heat '.$number,
                'sort_order' => $number,
            ]),
        );
        Competitor::factory()->create(['pill_number' => 40, 'car_number' => '40', 'first_name' => 'Dale', 'last_name' => 'High']);
        Competitor::factory()->create(['pill_number' => 11, 'car_number' => '11', 'first_name' => 'Ann', 'last_name' => 'First']);
        Competitor::factory()->create(['pill_number' => 22, 'car_number' => '22', 'first_name' => 'Bob', 'last_name' => 'Second']);
        Competitor::factory()->create(['pill_number' => 13, 'car_number' => '13', 'first_name' => 'Cara', 'last_name' => 'Third']);
        Competitor::factory()->create(['pill_number' => 14, 'car_number' => '14', 'first_name' => 'Dan', 'last_name' => 'Fourth']);
        Competitor::factory()->create(['pill_number' => 35, 'car_number' => '35', 'first_name' => 'Eve', 'last_name' => 'Fifth']);
        Competitor::factory()->create(['pill_number' => null, 'car_number' => '99', 'first_name' => 'No', 'last_name' => 'Pill']);

        $this->get(route('pill-draw'))
            ->assertOk()
            ->assertSee('Projected')
            ->assertSee('Hotlaps / Heat Race Groups')
            ->assertSee('6 drawn')
            ->assertSee('becomes official when every car has drawn')
            ->assertSeeInOrder([
                'Heat 1',
                'Ann First',
                'Pill 11',
                'Dale High',
                'Pill 40',
                'Heat 2',
                'Cara Third',
                'Pill 13',
                'Heat 3',
                'Dan Fourth',
                'Pill 14',
                'Heat 4',
                'Bob Second',
                'Pill 22',
                'Heat 5',
                'Eve Fifth',
                'Pill 35',
                'Still to draw',
                'No Pill',
            ], false)
            ->assertDontSee('>Inside</span>', false);
    }

    public function test_two_or_fewer_heats_use_inside_outside_lanes(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        Competitor::factory()->create(['pill_number' => 11, 'car_number' => '11', 'first_name' => 'Ann', 'last_name' => 'First']);
        Competitor::factory()->create(['pill_number' => 22, 'car_number' => '22', 'first_name' => 'Bob', 'last_name' => 'Second']);

        $this->get(route('pill-draw'))
            ->assertOk()
            ->assertSee('Inside')
            ->assertSee('Outside')
            ->assertSee('tv-heat__grid--lanes', false);
    }

    public function test_undrawn_drivers_leave_the_waiting_list_after_drawing(): void
    {
        Competitor::factory()->create(['pill_number' => null, 'car_number' => '55', 'first_name' => 'Wait', 'last_name' => 'Ing']);
        $drawn = Competitor::factory()->create(['pill_number' => null, 'car_number' => '77', 'first_name' => 'Soon', 'last_name' => 'Drawn']);

        $page = Livewire::test('pages::pill-draw')
            ->assertSee('Projected')
            ->assertSee('2 drivers')
            ->assertSee('Wait Ing')
            ->assertSee('Soon Drawn');

        $drawn->update(['pill_number' => 12]);
        $page->call('refreshBoard')
            ->assertSee('1 driver')
            ->assertSeeInOrder(['Still to draw', 'Wait Ing'], false)
            ->assertSee('Pill 12')
            ->assertSee('Soon Drawn')
            ->assertDontSeeHtml('undrawn-'.$drawn->id);
    }

    public function test_board_marks_groups_official_when_every_car_has_drawn(): void
    {
        Competitor::factory()->create(['pill_number' => 3, 'car_number' => '3', 'first_name' => 'Ann', 'last_name' => 'First']);
        Competitor::factory()->create(['pill_number' => 8, 'car_number' => '8', 'first_name' => 'Bob', 'last_name' => 'Second']);

        $this->get(route('pill-draw'))
            ->assertOk()
            ->assertSee('Official')
            ->assertSee('these groups are official')
            ->assertSee('Every car has drawn. These Hotlaps / Heat Race Groups are now official.');
    }

    public function test_board_falls_back_to_five_heats_when_no_heat_type_exists(): void
    {
        Competitor::factory()->create(['pill_number' => 7, 'first_name' => 'Ann', 'last_name' => 'First']);

        $this->get(route('pill-draw'))
            ->assertOk()
            ->assertSee('Heat 1')
            ->assertSee('Heat 5')
            ->assertSee('Ann First');
    }

    public function test_updating_a_pill_reshuffles_the_board_without_writing_lineups(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);
        $ann = Competitor::factory()->create(['pill_number' => 10, 'car_number' => '10', 'first_name' => 'Ann', 'last_name' => 'First']);
        $bob = Competitor::factory()->create(['pill_number' => 20, 'car_number' => '20', 'first_name' => 'Bob', 'last_name' => 'Second']);

        $page = Livewire::test('pages::pill-draw')
            ->assertSee('Ann First')
            ->assertSee('Bob Second');

        $this->assertSame(0, RaceEntry::query()->count());

        $ann->update(['pill_number' => 30]);
        $page->call('refreshBoard')
            ->assertSeeInOrder(['Heat 1', 'Bob Second', 'Heat 2', 'Ann First'], false);

        $this->assertSame(0, RaceEntry::query()->count());
        $this->assertSame(0, $race->fresh()->entries()->count());
    }

    public function test_board_shows_the_driver_currently_drawing(): void
    {
        $drawing = Competitor::factory()->create([
            'pill_number' => null,
            'car_number' => '47',
            'first_name' => 'Ann',
            'last_name' => 'First',
        ]);
        Competitor::factory()->create([
            'pill_number' => null,
            'car_number' => '99',
            'first_name' => 'Bob',
            'last_name' => 'Second',
        ]);
        Setting::putPillDrawCompetitorId($drawing->id);

        Livewire::test('pages::pill-draw')
            ->assertSee('Now drawing')
            ->assertSee('#47')
            ->assertSee('Ann First')
            ->assertSee('Drawing')
            ->assertSee('bg-amber-400/15', false)
            ->assertDontSee('>Pill</p>', false);

        Setting::putPillDrawPendingPill(33);

        Livewire::test('pages::pill-draw')
            ->call('refreshBoard')
            ->assertSee('Now drawing')
            ->assertSee('>Pill</p>', false)
            ->assertSee('>33</p>', false);

        Setting::putPillDrawCompetitorId(null);

        Livewire::test('pages::pill-draw')
            ->call('refreshBoard')
            ->assertDontSee('Now drawing');

        $this->assertNull(Setting::pillDrawPendingPill());
    }

    public function test_newly_drawn_and_relocated_drivers_are_marked_fresh_for_fly_animation(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        collect(range(1, 2))->each(
            fn (int $number) => Race::factory()->for($type)->create([
                'name' => 'Heat '.$number,
                'sort_order' => $number,
            ]),
        );
        Competitor::factory()->create(['pill_number' => 10, 'first_name' => 'Ann', 'last_name' => 'First']);
        $second = Competitor::factory()->create(['pill_number' => null, 'first_name' => 'Bob', 'last_name' => 'Second']);

        $board = Livewire::test('pages::pill-draw')
            ->assertSee('Ann First')
            ->assertSee('Bob Second');

        $second->update(['pill_number' => 5]);
        $board->call('refreshBoard');

        $drivers = collect($board->get('board')['heats'])
            ->flatMap(fn (array $heat, int $heatIndex) => collect($heat['drivers'])->map(
                fn (array $driver): array => $driver + ['heat' => $heatIndex],
            ));

        $bob = $drivers->firstWhere('name', 'Bob Second');
        $ann = $drivers->firstWhere('name', 'Ann First');

        $this->assertTrue($bob['fresh']);
        $this->assertTrue($ann['fresh']);
        $this->assertSame(0, $bob['heat']);
        $this->assertSame(1, $ann['heat']);
    }

    public function test_competitors_page_links_to_the_pill_draw_tv(): void
    {
        $this->get(route('competitors'))
            ->assertOk()
            ->assertSee('Pill Draw TV')
            ->assertSee(route('pill-draw'), false);
    }

    public function test_nav_shows_pill_draw_for_competitors_users(): void
    {
        $this->get(route('competitors'))
            ->assertOk()
            ->assertSee('>Pill Draw</a>', false);
    }
}
