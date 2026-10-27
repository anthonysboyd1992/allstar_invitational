<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_timing_page_lists_both_files_for_every_race(): void
    {
        $heat1 = Race::factory()->create(['name' => 'Heat 1']);
        $heat2 = Race::factory()->create(['name' => 'Heat 2', 'laps' => null]);

        $this->get(route('timing'))
            ->assertOk()
            ->assertSee(route('exports.transponders', $heat1), false)
            ->assertSee(route('exports.laps', $heat1), false)
            ->assertSee(route('exports.transponders', $heat2), false)
            ->assertSee(route('exports.laps', $heat2), false)
            ->assertSee('No laps');
    }

    public function test_race_board_no_longer_has_timing_downloads(): void
    {
        Race::factory()->create();

        $this->get(route('lineups'))->assertOk()->assertDontSee('Timing files');
    }

    public function test_flags_drivers_missing_transponders(): void
    {
        $race = Race::factory()->create();
        $race->syncLineup(Competitor::factory()->count(2)->create(['transponder' => null])->pluck('id')->all());

        $this->get(route('timing'))->assertSee('2 missing transponders');
    }

    public function test_laps_and_transponders_can_be_fixed_from_the_timing_page(): void
    {
        $race = Race::factory()->create(['laps' => null]);
        $driver = Competitor::factory()->create(['transponder' => null]);

        Livewire::test('pages::timing')
            ->call('updateLaps', $race->id, '12')
            ->call('updateTransponder', $driver->id, ' 108571 ');

        $this->assertSame(12, $race->refresh()->laps);
        $this->assertSame('108571', $driver->refresh()->transponder);
    }
}
