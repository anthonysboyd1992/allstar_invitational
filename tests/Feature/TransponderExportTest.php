<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransponderExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_race_csv_lists_its_drivers_in_starting_order(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1']);
        $otherRace = Race::factory()->create(['name' => 'Heat 2']);

        $shultz = Competitor::factory()->create(['transponder' => '108571', 'car_number' => '35', 'last_name' => 'Shultz', 'first_name' => 'Jason']);
        $obrien = Competitor::factory()->create(['transponder' => '200001', 'car_number' => '5G', 'last_name' => "O'Brien", 'first_name' => 'Justin']);
        $noTransponder = Competitor::factory()->create(['transponder' => null]);
        $elsewhere = Competitor::factory()->create(['transponder' => '999999']);

        $race->syncLineup([$shultz->id, $noTransponder->id, $obrien->id]);
        $otherRace->syncLineup([$elsewhere->id]);

        $response = $this->get(route('exports.transponders', $race))->assertOk();

        $this->assertSame('attachment; filename="Heat 1.csv"', $response->headers->get('Content-Disposition'));
        $this->assertSame(
            "\"Trns ID\",#,\"Last Name\",\"First Name\",Misc,\"Backup Trns ID\"\r\n"
            ."108571,35,Shultz,Jason,,\r\n"
            ."200001,5G,O'Brien,Justin,,\r\n",
            $response->streamedContent(),
        );
    }

    public function test_file_name_drops_characters_computers_cannot_save(): void
    {
        $race = Race::factory()->create(['name' => 'B-Main 1/2']);

        $this->get(route('exports.transponders', $race))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="B-Main 1-2.csv"');
    }

    public function test_each_race_has_a_matching_dat_file_with_its_laps(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1', 'laps' => 8]);
        $noLaps = Race::factory()->create(['name' => 'Hot Laps', 'laps' => null]);

        $response = $this->get(route('exports.laps', $race))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="Heat 1.dat"');
        $this->assertSame("Laps=8\r\n", $response->streamedContent());

        $this->assertSame("Laps=0\r\n", $this->get(route('exports.laps', $noLaps))->streamedContent());
    }
}
