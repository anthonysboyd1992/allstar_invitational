<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResultsPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_printed_results_list_finish_order_transfers_and_charge(): void
    {
        $heat = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true, 'assigns_by_pill' => true]);
        $dash = RaceType::factory()->create(['name' => 'Dash']);
        RaceTypeTransferRule::factory()->for($heat)->create([
            'position_from' => 1,
            'position_to' => 1,
            'destination_race_type_id' => $dash->id,
            'distribute' => RaceTypeTransferRule::DistributeAppend,
        ]);
        Race::factory()->for($dash)->create(['name' => 'Dash']);
        $race = Race::factory()->for($heat)->create(['name' => 'Heat 1', 'sponsor' => 'ACME']);
        $winner = Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
            'hometown' => 'Traverse City, MI',
            'accolades' => '2025 WoodTic Champion',
            'pill_number' => 12,
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
        $race->syncLineup([$second->id, $winner->id, $unchanged->id]);
        $entries = $race->entries()->orderBy('start_position')->get();
        $entries[1]->update(['finish_position' => 1]);
        $entries[0]->update(['finish_position' => 2]);
        $entries[2]->update(['finish_position' => 3]);

        $this->get(route('results.print', ['races' => [$race->id]]))
            ->assertOk()
            ->assertSee('class="letterhead-kind">Results</p>', false)
            ->assertSee('ACME - HEAT 1')
            ->assertSee('class="charge">Charge</th>', false)
            ->assertSee('class="transfer">Transfer</th>', false)
            ->assertSeeInOrder([
                'class="kart">Kart Assignment</th>',
                'class="charge">Charge</th>',
                'class="pos">1</td>',
                'Collin Thirlby',
                'class="transfer">Dash</td>',
                'class="charge charge-up">+1</td>',
                'class="pos">2</td>',
                'Conner Zbozien',
                'class="charge charge-down">-1</td>',
                'class="pos">3</td>',
                'Max Stambaugh',
                'class="charge"></td>',
            ], false)
            ->assertDontSee('class="weight"', false)
            ->assertDontSee('class="pill"', false)
            ->assertDontSee('Print entry list')
            ->assertSee('letter landscape')
            ->assertSee('#ee6426')
            ->assertSee('Back to results');
    }

    public function test_print_picker_builds_results_url(): void
    {
        $type = RaceType::factory()->create(['name' => 'Heat', 'has_results' => true]);
        $race = Race::factory()->for($type)->create();

        Livewire::test('pages::results')
            ->call('openPrintPicker', $type->id)
            ->assertSee('Print selected')
            ->assertDontSee('Print entry list')
            ->assertSee('/results/print?races%5B0%5D='.$race->id, false);
    }

    public function test_print_only_includes_the_selected_races(): void
    {
        $type = RaceType::factory()->create(['has_results' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Heat 1', 'sort_order' => 1]);
        Race::factory()->for($type)->create(['name' => 'Heat 2', 'sort_order' => 2]);

        $this->get(route('results.print', ['races' => [$first->id]]))
            ->assertOk()
            ->assertSee('HEAT 1')
            ->assertDontSee('HEAT 2');
    }

    public function test_unknown_race_ids_are_rejected(): void
    {
        $this->get(route('results.print', ['races' => [999]]))
            ->assertSessionHasErrors('races.0');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('results.print'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_results(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('results.print'))->assertForbidden();
    }
}
