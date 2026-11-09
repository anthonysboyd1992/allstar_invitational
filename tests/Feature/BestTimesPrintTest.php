<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\TimingFeedResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BestTimesPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_printed_best_times_rank_by_race_lap_and_show_the_day_best(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sponsor' => 'ACME']);
        $ann = Competitor::factory()->create([
            'car_number' => '12',
            'transponder' => '12000',
            'first_name' => 'Ann',
            'last_name' => 'First',
        ]);
        $bob = Competitor::factory()->create([
            'car_number' => '7',
            'transponder' => '7000',
            'first_name' => 'Bob',
            'last_name' => 'Second',
        ]);
        $race->syncLineup([$ann->id, $bob->id]);

        TimingFeedResult::factory()->create([
            'name' => 'Hot Laps',
            'standings' => [
                $this->standing('12', '12000', 'Ann', 'First', '16.100'),
                $this->standing('7', '7000', 'Bob', 'Second', '16.800'),
            ],
        ]);
        TimingFeedResult::factory()->applied()->create([
            'name' => 'Heat 1',
            'applied_to_race_id' => $race->id,
            'standings' => [
                $this->standing('12', '12000', 'Ann', 'First', '16.400'),
                $this->standing('7', '7000', 'Bob', 'Second', '16.200'),
            ],
        ]);
        TimingFeedResult::factory()->ignored()->create([
            'name' => 'Practice',
            'standings' => [
                $this->standing('12', '12000', 'Ann', 'First', '15.900'),
            ],
        ]);

        $this->get(route('results.best-times.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('class="letterhead-kind">Best Times</p>', false)
            ->assertSee('ACME - HEAT 1')
            ->assertSee('class="lap">Race</th>', false)
            ->assertSee('class="lap">Day</th>', false)
            ->assertSeeInOrder([
                'class="rank">1</td>',
                'Bob Second',
                'class="lap">16.200</td>',
                'class="lap">16.200</td>',
                'class="rank">2</td>',
                'Ann First',
                'class="lap">16.400</td>',
                'class="lap">15.900</td>',
            ], false)
            ->assertDontSee('16.100')
            ->assertDontSee('class="passes"', false)
            ->assertSee('letter landscape')
            ->assertSee('#ee6426')
            ->assertSee('Back to results');
    }

    public function test_print_picker_builds_best_times_url(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true]);
        $race = Race::factory()->for($type)->create();

        Livewire::test('pages::results')
            ->call('openPrintPicker', $type->id)
            ->assertSee('Print best times')
            ->assertSee('/results/best-times/print?races%5B0%5D='.$race->id, false);
    }

    public function test_print_only_includes_the_selected_races(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);

        $this->get(route('results.best-times.print', ['races' => [$first->id]]))
            ->assertOk()
            ->assertSee('HEAT 1')
            ->assertDontSee('HEAT 2');
    }

    public function test_escapes_driver_names_on_the_sheet(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $race = Race::factory()->for($type)->create();
        $driver = Competitor::factory()->create([
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Driver',
        ]);
        $race->syncLineup([$driver->id]);

        $this->get(route('results.best-times.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Driver', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unknown_race_ids_are_rejected(): void
    {
        $this->get(route('results.best-times.print', ['races' => [999]]))
            ->assertSessionHasErrors('races.0');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('results.best-times.print'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_best_times(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('results.best-times.print'))->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function standing(string $number, string $transponder, string $first, string $last, string $bestLap): array
    {
        return [
            'position' => 1,
            'number' => $number,
            'transponder' => $transponder,
            'registration' => $number,
            'laps' => 8,
            'total_time' => '00:02:14.000',
            'first_name' => $first,
            'last_name' => $last,
            'last_lap' => $bestLap,
            'best_lap' => $bestLap,
        ];
    }
}
