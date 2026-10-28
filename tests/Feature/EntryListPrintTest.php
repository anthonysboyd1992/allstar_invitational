<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryListPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_printed_entry_list_includes_competitor_weight(): void
    {
        Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
            'hometown' => 'Traverse City, MI',
            'weight' => 210.0,
            'accolades' => '2025 WoodTic Champion',
            'pill_number' => 12,
        ]);

        $this->get(route('competitors.print'))
            ->assertOk()
            ->assertDontSee('class="letterhead-kind"', false)
            ->assertSee('Entry list')
            ->assertSee('class="weight">Weight</th>', false)
            ->assertSee('class="weight">210</td>', false)
            ->assertSee('Collin Thirlby')
            ->assertSee('Traverse City, MI')
            ->assertSee('class="pill">12</td>', false)
            ->assertDontSee('class="transfer"', false)
            ->assertDontSee('Kart Assignment')
            ->assertSee('letter landscape')
            ->assertSee('#ee6426')
            ->assertSee('Back to competitors');
    }

    public function test_competitors_page_links_to_the_entry_list(): void
    {
        $this->get(route('competitors'))
            ->assertOk()
            ->assertSee('Print entry list')
            ->assertSee(route('competitors.print', ['sort' => 'car_number']), false);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('competitors.print'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_the_entry_list(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('competitors.print'))->assertForbidden();
    }
}
