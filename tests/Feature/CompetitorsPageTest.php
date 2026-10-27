<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitorsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pages_render_without_on_air_controls(): void
    {
        Competitor::factory()->create(['first_name' => 'Gat']);

        $this->get(route('competitors'))->assertOk()->assertSee('Gat')->assertDontSee('ON AIR');
        $this->get(route('vmix'))->assertOk()->assertSee('Lineup feed');
    }

    public function test_adding_a_competitor_with_every_field(): void
    {
        Livewire::test('pages::competitors')
            ->call('openAddModal')
            ->assertSet('showFormModal', true)
            ->set('form', [
                'car_number' => '5G',
                'first_name' => 'Gat',
                'last_name' => 'Leytham',
                'hometown' => 'Marshalltown IA',
                'weight' => '185',
                'accolades' => '3x track champion',
                'transponder' => '1234567',
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('competitors', [
            'car_number' => '5G',
            'first_name' => 'Gat',
            'last_name' => 'Leytham',
            'hometown' => 'Marshalltown IA',
            'weight' => 185,
            'accolades' => '3x track champion',
            'transponder' => '1234567',
        ]);
    }

    public function test_save_and_add_another_keeps_the_modal_open_with_a_blank_form(): void
    {
        Livewire::test('pages::competitors')
            ->call('openAddModal')
            ->set('form.first_name', 'Tim')
            ->set('form.last_name', 'Ward')
            ->call('save', true)
            ->assertSet('showFormModal', true)
            ->assertSet('form.first_name', '');

        $this->assertDatabaseHas('competitors', ['first_name' => 'Tim', 'last_name' => 'Ward']);
    }

    public function test_edit_modal_loads_and_saves_accolades(): void
    {
        $competitor = Competitor::factory()->create(['accolades' => 'Rookie of the Year']);

        Livewire::test('pages::competitors')
            ->call('openEditModal', $competitor->id)
            ->assertSet('form.accolades', 'Rookie of the Year')
            ->set('form.accolades', '2025 Champion')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertSame('2025 Champion', $competitor->refresh()->accolades);
        $this->assertSame(1, Competitor::query()->count());
    }

    public function test_names_are_required(): void
    {
        Livewire::test('pages::competitors')
            ->call('openAddModal')
            ->call('save')
            ->assertHasErrors(['form.first_name', 'form.last_name']);
    }

    public function test_drawing_a_pill_never_repeats_a_drawn_number(): void
    {
        foreach (range(1, 99) as $pill) {
            Competitor::factory()->create(['pill_number' => $pill]);
        }
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        $pill = Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->call('drawPill')
            ->effects['returns'][0] ?? null;

        $this->assertSame(100, $pill);
    }

    public function test_a_drawn_pill_fills_the_field_and_only_saves_on_save(): void
    {
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        $component = Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->call('drawPill');

        $drawn = $component->get('drawnPill');
        $component->assertSet('manualPill', '');

        $component->call('revealDrawnPill')->assertSet('manualPill', (string) $drawn);
        $this->assertNull($competitor->refresh()->pill_number);

        $component->call('savePill')->assertHasNoErrors()->assertSet('pillCompetitorId', null);
        $this->assertSame($drawn, $competitor->refresh()->pill_number);
    }

    public function test_a_drawn_pill_cannot_be_changed_or_redrawn(): void
    {
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        $component = Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->call('drawPill');

        $drawn = $component->get('drawnPill');

        $component->call('drawPill')->assertSet('drawnPill', $drawn);

        $component->set('manualPill', $drawn === 50 ? '51' : '50')->call('savePill');
        $this->assertSame($drawn, $competitor->refresh()->pill_number);
    }

    public function test_cancelling_a_draw_does_not_save_it(): void
    {
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->call('drawPill')
            ->call('closePillModal')
            ->assertSet('drawnPill', null);

        $this->assertNull($competitor->refresh()->pill_number);
    }

    public function test_drawing_when_every_pill_is_taken_shows_an_error(): void
    {
        foreach (range(1, 100) as $pill) {
            Competitor::factory()->create(['pill_number' => $pill]);
        }
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->call('drawPill')
            ->assertDispatched('notify', type: 'error');

        $this->assertNull($competitor->refresh()->pill_number);
    }

    public function test_drawn_pills_stay_between_1_and_100(): void
    {
        $competitors = Competitor::factory()->count(20)->create(['pill_number' => null]);
        $component = Livewire::test('pages::competitors');

        foreach ($competitors as $competitor) {
            $component->call('openPillModal', $competitor->id)->call('drawPill')->call('savePill');
        }

        $pills = Competitor::query()->pluck('pill_number');
        $this->assertCount(20, $pills->unique());
        $this->assertTrue($pills->every(fn (int $pill): bool => $pill >= 1 && $pill <= 100));
    }

    public function test_manual_pill_entry_must_be_unique(): void
    {
        Competitor::factory()->create(['pill_number' => 7]);
        $competitor = Competitor::factory()->create(['pill_number' => null]);

        Livewire::test('pages::competitors')
            ->call('openPillModal', $competitor->id)
            ->set('manualPill', '7')
            ->call('savePill')
            ->assertHasErrors('manualPill')
            ->set('manualPill', '42')
            ->call('savePill')
            ->assertHasNoErrors()
            ->assertSet('pillCompetitorId', null);

        $this->assertSame(42, $competitor->refresh()->pill_number);
    }

    public function test_inline_edit_updates_a_field(): void
    {
        $competitor = Competitor::factory()->create();

        Livewire::test('pages::competitors')
            ->call('updateField', $competitor->id, 'weight', '201')
            ->call('updateField', $competitor->id, 'id', '99');

        $this->assertSame(201, $competitor->refresh()->weight);
    }

    public function test_mass_delete(): void
    {
        $competitors = Competitor::factory()->count(3)->create();

        Livewire::test('pages::competitors')
            ->set('selected', [$competitors[0]->id, $competitors[1]->id])
            ->call('deleteSelected');

        $this->assertSame([$competitors[2]->id], Competitor::query()->pluck('id')->all());
    }

    public function test_competitors_sort_by_car_number_then_last_name(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([['10', 'Adams'], [null, 'Baker'], ['71H', 'Cole'], ['9', 'Zane'], ['9', 'Avery'], ['71', 'Dunn']] as [$carNumber, $lastName]) {
            Competitor::factory()->create(['car_number' => $carNumber, 'last_name' => $lastName]);
        }

        $this->assertSame(
            ['Avery', 'Zane', 'Adams', 'Dunn', 'Cole', 'Baker'],
            Livewire::test('pages::competitors')->get('competitors')->pluck('last_name')->all(),
        );
    }
}
