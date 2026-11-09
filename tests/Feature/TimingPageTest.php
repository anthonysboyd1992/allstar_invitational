<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
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
            ->assertSee(route('exports.race-zip', $heat1), false)
            ->assertSee(route('exports.transponders', $heat2), false)
            ->assertSee(route('exports.laps', $heat2), false)
            ->assertSee(route('exports.race-zip', $heat2), false)
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

    public function test_grouped_types_offer_one_csv_instead_of_a_file_per_group(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'combines_groups' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Hotlaps 1', 'laps' => 4]);
        $second = Race::factory()->for($type)->create(['name' => 'Hotlaps 2', 'laps' => 4]);
        $heat = Race::factory()->create(['name' => 'Heat 1']);

        $this->get(route('timing'))
            ->assertOk()
            ->assertSee(route('exports.type-transponders', $type), false)
            ->assertSee(route('exports.type-laps', $type), false)
            ->assertSee(route('exports.type-zip', $type), false)
            ->assertDontSee(route('exports.transponders', $first), false)
            ->assertDontSee(route('exports.laps', $first), false)
            ->assertDontSee(route('exports.transponders', $second), false)
            ->assertDontSee(route('exports.laps', $second), false)
            ->assertDontSee('Hotlaps 1')
            ->assertDontSee('Hotlaps 2')
            ->assertSee(route('exports.transponders', $heat), false);
    }

    public function test_laps_on_a_grouped_type_update_every_group(): void
    {
        $type = RaceType::factory()->create(['combines_groups' => true]);
        $first = Race::factory()->for($type)->create(['laps' => 4]);
        $second = Race::factory()->for($type)->create(['laps' => 4]);

        Livewire::test('pages::timing')->call('updateLaps', $first->id, '8');

        $this->assertSame(8, $first->refresh()->laps);
        $this->assertSame(8, $second->refresh()->laps);
    }
}
