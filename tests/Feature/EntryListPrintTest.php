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
            'primary_division' => 'IMCA Modified',
            'weight' => 210.0,
            'accolades' => '2025 WoodTic Champion',
            'announcer_details' => 'Three-time feature winner at Hartford',
            'pill_number' => 12,
        ]);

        $this->get(route('competitors.print'))
            ->assertOk()
            ->assertDontSee('class="letterhead-kind"', false)
            ->assertSee('Entry list')
            ->assertSee('class="division">Primary Division</th>', false)
            ->assertSee('class="division">IMCA Modified</td>', false)
            ->assertSee('class="weight">Weight</th>', false)
            ->assertSee('class="weight">210</td>', false)
            ->assertSee('Collin Thirlby')
            ->assertSee('Traverse City, MI')
            ->assertSee('class="pill">12</td>', false)
            ->assertDontSee('class="announcer"', false)
            ->assertDontSee('Three-time feature winner at Hartford')
            ->assertDontSee('Announcer Entry List')
            ->assertDontSee('class="transfer"', false)
            ->assertDontSee('Kart Assignment')
            ->assertSee('letter landscape')
            ->assertSee('#ee6426')
            ->assertSee('Back to competitors');
    }

    public function test_printed_entry_list_can_omit_weight(): void
    {
        Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
            'weight' => 210.0,
        ]);

        $this->get(route('competitors.print', ['weight' => 0]))
            ->assertOk()
            ->assertSee('Entry list')
            ->assertSee('Collin Thirlby')
            ->assertSee('class="division">Primary Division</th>', false)
            ->assertDontSee('class="weight"', false)
            ->assertDontSee('>Weight</th>', false)
            ->assertDontSee('210');
    }

    public function test_announcer_entry_list_omits_weight_and_pill(): void
    {
        Competitor::factory()->create([
            'car_number' => '47',
            'first_name' => 'Collin',
            'last_name' => 'Thirlby',
            'hometown' => 'Traverse City, MI',
            'primary_division' => 'IMCA Modified',
            'weight' => 210.0,
            'accolades' => '2025 WoodTic Champion',
            'announcer_details' => 'Three-time feature winner at Hartford',
            'pill_number' => 12,
        ]);

        $this->get(route('competitors.print-announcer'))
            ->assertOk()
            ->assertDontSee('class="letterhead-kind"', false)
            ->assertSee('Announcer Entry List')
            ->assertSee('class="division">Primary Division</th>', false)
            ->assertSee('class="division">IMCA Modified</td>', false)
            ->assertSee('class="announcer">Announcer Details</th>', false)
            ->assertSee('class="announcer">Three-time feature winner at Hartford</td>', false)
            ->assertSee('Collin Thirlby')
            ->assertSee('Traverse City, MI')
            ->assertSee('2025 WoodTic Champion')
            ->assertDontSee('class="weight"', false)
            ->assertDontSee('>Weight</th>', false)
            ->assertDontSee('210')
            ->assertDontSee('class="pill"', false)
            ->assertDontSee('>Pill</th>', false);
    }

    public function test_competitors_page_links_to_the_entry_list(): void
    {
        $this->get(route('competitors'))
            ->assertOk()
            ->assertSee('Print entry list')
            ->assertSee('Print without weight')
            ->assertSee('Print announcer entry list')
            ->assertSee(route('competitors.print', ['sort' => 'car_number']), false)
            ->assertSee(route('competitors.print', ['sort' => 'car_number', 'weight' => 0]))
            ->assertSee(route('competitors.print-announcer', ['sort' => 'car_number']), false);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        auth()->logout();

        $this->get(route('competitors.print'))->assertRedirect(route('login'));
        $this->get(route('competitors.print-announcer'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_print_the_entry_list(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('competitors.print'))->assertForbidden();
        $this->get(route('competitors.print-announcer'))->assertForbidden();
    }
}
