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

class TvBoardTest extends TestCase
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

        $this->get(route('tv'))->assertRedirect(route('login'));
    }

    public function test_users_without_pill_draw_access_are_forbidden(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('tv'))->assertForbidden();
    }

    public function test_tv_defaults_to_pill_draw(): void
    {
        Competitor::factory()->create([
            'pill_number' => 1,
            'car_number' => '7',
            'first_name' => 'Ann',
            'last_name' => 'First',
        ]);

        $this->get(route('tv'))
            ->assertOk()
            ->assertSee('TV · Pill Draw')
            ->assertSee('Ann First')
            ->assertSee('Still to draw');
    }

    public function test_tv_shows_lineup_board_when_mode_is_lineups(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1']);
        $driver = Competitor::factory()->create(['first_name' => 'Ann', 'last_name' => 'First']);
        $race->syncLineup([$driver->id]);
        Setting::putLineupBoardRaceIds([$race->id]);
        Setting::putTvBoardMode(Setting::TvBoardLineup);

        $this->get(route('tv'))
            ->assertOk()
            ->assertSee('TV · Lineup Board')
            ->assertSee('Ann First')
            ->assertSee('Heat 1')
            ->assertDontSee('Still to draw')
            ->assertDontSee('Races to show');
    }

    public function test_pill_draw_control_can_put_lineups_on_tv(): void
    {
        Livewire::test('pages::pill-draw')
            ->assertSee('Shared TV screen')
            ->assertSee('On air:')
            ->call('showLineupBoardOnTv');

        $this->assertSame(Setting::TvBoardLineup, Setting::tvBoardMode());
    }

    public function test_lineup_board_control_can_put_pill_draw_on_tv(): void
    {
        Setting::putTvBoardMode(Setting::TvBoardLineup);

        Livewire::test('pages::lineup-board')
            ->assertSee('Shared TV screen')
            ->call('showPillDrawOnTv');

        $this->assertSame(Setting::TvBoardPillDraw, Setting::tvBoardMode());
    }

    public function test_saving_lineups_also_puts_lineups_on_tv(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat']);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Setting::putTvBoardMode(Setting::TvBoardPillDraw);

        Livewire::test('pages::lineup-board')
            ->set('raceIds', [(string) $race->id])
            ->call('saveToTv');

        $this->assertSame([$race->id], Setting::lineupBoardRaceIds());
        $this->assertSame(Setting::TvBoardLineup, Setting::tvBoardMode());
    }

    public function test_tv_poll_picks_up_mode_changes(): void
    {
        Competitor::factory()->create([
            'pill_number' => 1,
            'first_name' => 'Ann',
            'last_name' => 'First',
        ]);
        $race = Race::factory()->create(['name' => 'Heat 1']);
        $bob = Competitor::factory()->create(['first_name' => 'Bob', 'last_name' => 'Second']);
        $race->syncLineup([$bob->id]);
        Setting::putLineupBoardRaceIds([$race->id]);

        $tv = Livewire::test('pages::tv')
            ->assertSet('mode', Setting::TvBoardPillDraw)
            ->assertSee('Ann First');

        Setting::putTvBoardMode(Setting::TvBoardLineup);

        $tv->call('refreshMode')
            ->assertSet('mode', Setting::TvBoardLineup)
            ->assertSee('Bob Second')
            ->assertSee('Heat 1');
    }

    public function test_display_only_lineup_board_hides_the_picker(): void
    {
        Livewire::test('pages::lineup-board', ['displayOnly' => true])
            ->assertDontSee('Races to show')
            ->assertDontSee('>Save to TV</button>', false)
            ->assertDontSee('Shared TV screen')
            ->assertSee('Pick races on the Lineup Board control page');
    }

    public function test_nav_shows_tv_for_competitors_users(): void
    {
        $this->get(route('competitors'))
            ->assertOk()
            ->assertSee('>TV</a>', false)
            ->assertSee(route('tv'), false);
    }
}
