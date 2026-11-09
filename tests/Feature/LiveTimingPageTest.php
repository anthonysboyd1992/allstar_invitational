<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\Setting;
use App\Models\TimingFeedResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LiveTimingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_page_shows_the_live_session_standings(): void
    {
        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'flag' => 'Green',
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Heat 1')
            ->assertSee('Green')
            ->assertSee('Ann First')
            ->assertSee('12')
            ->assertSee('Full screen')
            ->assertSee('Gap')
            ->assertSee('Diff')
            ->assertSee('Speed')
            ->assertDontSee('>00:02:14.321<', false)
            ->assertDontSee('Hard charger')
            ->assertDontSee('Most passes today')
            ->assertDontSee('Transfer')
            ->assertDontSee('N/A')
            ->assertDontSee('—')
            ->assertDontSee('Idle');
    }

    public function test_page_shows_the_last_session_as_idle_when_none_are_live(): void
    {
        TimingFeedResult::factory()->create(['name' => 'Heat 1']);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Heat 1')
            ->assertSee('Idle');
    }

    public function test_page_fills_driver_names_from_competitors_when_the_feed_omits_them(): void
    {
        Competitor::factory()->create([
            'car_number' => '12',
            'transponder' => '52474',
            'first_name' => 'Ann',
            'last_name' => 'First',
        ]);
        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'standings' => [[
                'position' => 1,
                'number' => '12',
                'transponder' => '52474',
                'registration' => '12',
                'laps' => 3,
                'total_time' => '00:01:00.000',
                'first_name' => '',
                'last_name' => '',
            ]],
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Ann First')
            ->assertSee('12');
    }

    public function test_page_shows_an_empty_state_when_no_feed_has_arrived(): void
    {
        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Waiting for Race Manager')
            ->assertDontSee('Idle');
    }

    public function test_page_shows_laps_total_fastest_lap_hard_charger_and_transfer_line(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $feature = RaceType::factory()->create(['name' => 'Feature', 'has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'laps' => 8]);
        $starters = Competitor::factory()->count(4)->create();
        $bob = Competitor::factory()->create([
            'car_number' => '7',
            'transponder' => '11111',
            'first_name' => 'Bob',
            'last_name' => 'Second',
        ]);
        $race->syncLineup([...$starters->pluck('id')->all(), $bob->id]);
        RaceTypeTransferRule::factory()->create([
            'race_type_id' => $type->id,
            'destination_race_type_id' => $feature->id,
            'position_from' => 1,
            'position_to' => 4,
        ]);
        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'flag' => 'Green',
            'race_laps' => 8,
            'standings' => [[
                'position' => 1,
                'number' => '7',
                'transponder' => '11111',
                'registration' => '7',
                'laps' => 3,
                'total_time' => '00:01:00.000',
                'first_name' => 'Bob',
                'last_name' => 'Second',
                'last_lap' => '16.400',
                'best_lap' => '16.200',
                'start_position' => 5,
                'passes' => 4,
            ]],
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('3/8')
            ->assertSee('Fastest lap')
            ->assertSee('#7 Bob Second')
            ->assertSee('16.200')
            ->assertSee('22.222 mph')
            ->assertSee('Hard charger')
            ->assertSee('+4')
            ->assertSee('Most passes today')
            ->assertSee('Top 4')
            ->assertSee('Transfer · Top 4')
            ->assertSee('bg-amber-400', false);
    }

    public function test_page_deletes_a_session_and_shows_the_next_one(): void
    {
        $practice = TimingFeedResult::factory()->live()->create(['name' => 'Practice']);
        TimingFeedResult::factory()->create(['name' => 'Heat 1']);

        Livewire::test('pages::live-timing')
            ->assertSee('Practice')
            ->call('deleteSession', $practice->id)
            ->assertSee('Heat 1')
            ->assertDontSee('Practice')
            ->assertDontSee('Deleted sessions')
            ->assertDontSee('Restore')
            ->assertDispatched('notify', message: 'Deleted Practice.');

        $this->assertNotNull($practice->fresh()->ignored_at);
        $this->assertFalse($practice->fresh()->is_live);
    }

    public function test_page_does_not_list_deleted_sessions(): void
    {
        TimingFeedResult::factory()->ignored()->create(['name' => 'Practice']);

        Livewire::test('pages::live-timing')
            ->assertSee('Waiting for Race Manager')
            ->assertDontSee('Practice')
            ->assertDontSee('Restore')
            ->assertDontSee('Idle');
    }

    public function test_page_does_not_show_a_deleted_session_as_live(): void
    {
        TimingFeedResult::factory()->ignored()->create(['name' => 'Practice Junk']);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Waiting for Race Manager')
            ->assertDontSee('Practice Junk')
            ->assertDontSee('Restore')
            ->assertDontSee('Idle');
    }

    public function test_page_does_not_show_an_applied_session(): void
    {
        TimingFeedResult::factory()->create([
            'name' => 'Heat 1 Finish',
            'is_live' => true,
            'applied_at' => now(),
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Waiting for Race Manager')
            ->assertDontSee('Heat 1 Finish');
    }

    public function test_page_uses_last_lap_instead_of_a_zero_best_lap(): void
    {
        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'standings' => [
                [
                    'position' => 1,
                    'number' => '12',
                    'transponder' => '12000',
                    'laps' => 5,
                    'last_lap' => '5.370',
                    'best_lap' => '00:00:00.000',
                    'first_name' => 'Ann',
                    'last_name' => 'First',
                ],
            ],
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('5.370')
            ->assertDontSee('>00:00:00.000<', false);
    }

    public function test_page_shows_gap_to_the_car_ahead_and_diff_to_the_leader(): void
    {
        Setting::put(Setting::TrackLength, '0.250');

        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
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
                    'best_lap' => '16.200',
                ],
                [
                    'position' => 2,
                    'number' => '7',
                    'transponder' => '7000',
                    'laps' => 5,
                    'total_time' => '00:01:01.234',
                    'first_name' => 'Bob',
                    'last_name' => 'Second',
                    'last_lap' => '16.800',
                    'best_lap' => '16.400',
                ],
                [
                    'position' => 3,
                    'number' => '21',
                    'transponder' => '21000',
                    'laps' => 4,
                    'total_time' => '00:00:50.000',
                    'first_name' => 'Cara',
                    'last_name' => 'Third',
                    'last_lap' => '17.000',
                    'best_lap' => '16.900',
                ],
            ],
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('Gap')
            ->assertSee('Diff')
            ->assertSee('Speed')
            ->assertSee('54.545')
            ->assertSee('+1.234')
            ->assertSee('1 lap')
            ->assertDontSee('>00:01:00.000<', false);
    }

    public function test_page_shows_standings_after_a_feed_snapshot_arrives(): void
    {
        $page = Livewire::test('pages::live-timing')
            ->assertSee('Waiting for Race Manager');

        TimingFeedResult::factory()->live()->create([
            'name' => 'Heat 1',
            'flag' => 'Green',
        ]);

        $page->call('refreshBoard')
            ->assertSee('Heat 1')
            ->assertSee('Ann First')
            ->assertSee('12');
    }

    public function test_page_escapes_session_names_from_the_feed(): void
    {
        TimingFeedResult::factory()->live()->create([
            'name' => '<script>alert(1)</script>',
        ]);

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_timing_users_can_open_live_timing(): void
    {
        $this->actingAs(User::factory()->withAreas(['timing'])->create());

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('href="'.route('live-timing').'"', false);
    }

    public function test_results_users_can_open_live_timing(): void
    {
        $this->actingAs(User::factory()->withAreas(['results'])->create());

        $this->get(route('live-timing'))
            ->assertOk()
            ->assertSee('href="'.route('live-timing').'"', false);
    }

    public function test_vmix_users_cannot_open_live_timing(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['vmix']['areas'])->create());

        $this->get(route('live-timing'))->assertForbidden();
    }
}
