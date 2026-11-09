<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HardChargerPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_printed_hard_charger_ranks_by_passes_from_start_to_finish(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sponsor' => 'ACME']);
        $charger = Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
        ]);
        $second = Competitor::factory()->create([
            'car_number' => '45',
            'first_name' => 'Conner',
            'last_name' => 'Zbozien',
        ]);
        $unchanged = Competitor::factory()->create([
            'car_number' => '7',
            'first_name' => 'Max',
            'last_name' => 'Stambaugh',
        ]);
        $race->syncLineup([$second->id, $charger->id, $unchanged->id]);
        $entries = $race->entries()->orderBy('start_position')->get();
        $entries[1]->update(['finish_position' => 1]);
        $entries[0]->update(['finish_position' => 2]);
        $entries[2]->update(['finish_position' => 3]);

        $this->get(route('results.hard-charger.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('class="letterhead-kind">Hard Charger</p>', false)
            ->assertSee('ACME - HEAT 1')
            ->assertSee('class="passes">Passes</th>', false)
            ->assertDontSee('class="passes">1st</th>', false)
            ->assertDontSee('class="passes">Total</th>', false)
            ->assertSeeInOrder([
                'class="rank">1</td>',
                'Collin Thirlby',
                'class="spot">2</td>',
                'class="spot">1</td>',
                'class="passes charge-up">+1</td>',
                'class="rank">2</td>',
                'Max Stambaugh',
                'class="passes"></td>',
                'class="rank">3</td>',
                'Conner Zbozien',
                'class="passes"></td>',
            ], false)
            ->assertDontSee('charge-down', false)
            ->assertDontSee('>-1<', false)
            ->assertDontSee('class="transfer"', false)
            ->assertSee('letter landscape')
            ->assertSee('#ee6426')
            ->assertSee('Back to results')
            ->assertDontSee('class="lap">Race</th>', false)
            ->assertDontSee('class="lap">Day</th>', false);
    }

    public function test_inverted_feature_prints_both_halves_and_does_not_count_the_invert(): void
    {
        $type = RaceType::factory()->create(['name' => 'Feature', 'has_results' => true]);
        $race = Race::factory()->for($type)->create(['name' => 'Feature', 'sponsor' => 'ACME']);
        $leader = Competitor::factory()->create(['car_number' => '1', 'first_name' => 'First', 'last_name' => 'Start']);
        $middle = Competitor::factory()->create(['car_number' => '2', 'first_name' => 'Mid', 'last_name' => 'Pack']);
        $charger = Competitor::factory()->create(['car_number' => '8', 'first_name' => 'Hard', 'last_name' => 'Charger']);
        $race->syncLineup([$leader->id, $middle->id, $charger->id]);
        $entries = $race->entries()->orderBy('start_position')->get();
        $entries[0]->update([
            'break_position' => 3,
            'invert_start_position' => 1,
            'finish_position' => 3,
        ]);
        $entries[1]->update([
            'break_position' => 2,
            'invert_start_position' => 2,
            'finish_position' => 2,
        ]);
        $entries[2]->update([
            'break_position' => 1,
            'invert_start_position' => 3,
            'finish_position' => 1,
        ]);
        $race->update([
            'inverted_at' => now(),
            'invert_count' => 8,
        ]);

        $this->get(route('results.hard-charger.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('ACME - FEATURE · Top 8 invert')
            ->assertSee('class="passes">1st</th>', false)
            ->assertSee('class="passes">2nd</th>', false)
            ->assertSee('class="passes">Total</th>', false)
            ->assertSeeInOrder([
                'class="rank">1</td>',
                'Hard Charger',
                'class="spot">3</td>',
                'class="spot">1</td>',
                'class="passes charge-up">+2</td>',
                'class="spot">3</td>',
                'class="spot">1</td>',
                'class="passes charge-up">+2</td>',
                'class="passes charge-up">+4</td>',
                'class="rank">2</td>',
                'Mid Pack',
                'class="rank">3</td>',
                'First Start',
                'class="passes"></td>',
            ], false)
            ->assertDontSee('charge-down', false)
            ->assertDontSee('>-2<', false)
            ->assertDontSee('>-4<', false);
    }

    public function test_print_picker_builds_hard_charger_url(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true]);
        $race = Race::factory()->for($type)->create();

        Livewire::test('pages::results')
            ->call('openPrintPicker', $type->id)
            ->assertSee('Print hard charger')
            ->assertSee('/results/hard-charger/print?races%5B0%5D='.$race->id, false);
    }

    public function test_print_only_includes_the_selected_races(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);

        $this->get(route('results.hard-charger.print', ['races' => [$first->id]]))
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
        $race->entries()->first()->update(['finish_position' => 1]);

        $this->get(route('results.hard-charger.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Driver', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unknown_race_ids_are_rejected(): void
    {
        $this->get(route('results.hard-charger.print', ['races' => [999]]))
            ->assertSessionHasErrors('races.0');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('results.hard-charger.print'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_hard_charger(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('results.hard-charger.print'))->assertForbidden();
    }
}
