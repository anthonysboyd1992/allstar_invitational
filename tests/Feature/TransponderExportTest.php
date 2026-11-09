<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

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
            "108571,35,Shultz,Jason,,\r\n"
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
        $this->assertSame(
            "Laps,8\r\nLength,0.100\r\nDuration,0\r\nBreakout,0.000\r\n",
            $response->streamedContent(),
        );

        $this->assertSame(
            "Laps,0\r\nLength,0.100\r\nDuration,0\r\nBreakout,0.000\r\n",
            $this->get(route('exports.laps', $noLaps))->streamedContent(),
        );
    }

    public function test_a_grouped_type_downloads_one_csv_for_every_group(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'combines_groups' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Hotlaps 1', 'sort_order' => 2, 'laps' => 3]);
        $second = Race::factory()->for($type)->create(['name' => 'Hotlaps 2', 'sort_order' => 1, 'laps' => 3]);
        $other = Race::factory()->create(['name' => 'Heat 1']);

        $firstDriver = Competitor::factory()->create(['transponder' => '111', 'car_number' => '1', 'last_name' => 'First', 'first_name' => 'Ann']);
        $secondDriver = Competitor::factory()->create(['transponder' => '222', 'car_number' => '2', 'last_name' => 'Second', 'first_name' => 'Bob']);
        $skipped = Competitor::factory()->create(['transponder' => null, 'car_number' => '3']);
        $elsewhere = Competitor::factory()->create(['transponder' => '999', 'car_number' => '9']);

        $second->syncLineup([$secondDriver->id]);
        $first->syncLineup([$firstDriver->id, $skipped->id]);
        $other->syncLineup([$elsewhere->id]);

        $response = $this->get(route('exports.type-transponders', $type))->assertOk();

        $this->assertStringContainsString('Hotlaps.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame(
            "111,1,First,Ann,,\r\n"
            ."222,2,Second,Bob,,\r\n",
            $response->streamedContent(),
        );
        $this->assertSame(
            "Laps,3\r\nLength,0.100\r\nDuration,0\r\nBreakout,0.000\r\n",
            $this->get(route('exports.type-laps', $type))->streamedContent(),
        );
        $this->assertStringContainsString('Hotlaps.dat', $this->get(route('exports.type-laps', $type))->headers->get('Content-Disposition'));
    }

    public function test_dat_length_uses_the_track_length_from_settings(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1', 'laps' => 12]);
        Setting::put(Setting::TrackLength, '0.250');

        $this->assertSame(
            "Laps,12\r\nLength,0.250\r\nDuration,0\r\nBreakout,0.000\r\n",
            $this->get(route('exports.laps', $race))->streamedContent(),
        );
    }

    public function test_a_race_zip_contains_its_csv_and_dat(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 1', 'laps' => 8]);
        $driver = Competitor::factory()->create(['transponder' => '108571', 'car_number' => '35', 'last_name' => 'Shultz', 'first_name' => 'Jason']);
        $race->syncLineup([$driver->id]);

        $response = $this->get(route('exports.race-zip', $race))->assertOk();
        $response->assertDownload('Heat 1.zip');

        $files = $this->zipFiles($response);
        $this->assertSame(
            "108571,35,Shultz,Jason,,\r\n",
            $files['Heat 1.csv'],
        );
        $this->assertSame(
            "Laps,8\r\nLength,0.100\r\nDuration,0\r\nBreakout,0.000\r\n",
            $files['Heat 1.dat'],
        );
    }

    public function test_a_grouped_type_zip_uses_the_type_name(): void
    {
        $type = RaceType::factory()->create(['name' => 'Hotlaps', 'combines_groups' => true]);
        $first = Race::factory()->for($type)->create(['name' => 'Hotlaps 1', 'sort_order' => 1, 'laps' => 3]);
        Race::factory()->for($type)->create(['name' => 'Hotlaps 2', 'sort_order' => 2, 'laps' => 3]);
        $driver = Competitor::factory()->create(['transponder' => '111', 'car_number' => '1', 'last_name' => 'First', 'first_name' => 'Ann']);
        $first->syncLineup([$driver->id]);

        $response = $this->get(route('exports.type-zip', $type))->assertOk();
        $response->assertDownload('Hotlaps.zip');

        $files = $this->zipFiles($response);
        $this->assertSame("111,1,First,Ann,,\r\n", $files['Hotlaps.csv']);
        $this->assertSame(
            "Laps,3\r\nLength,0.100\r\nDuration,0\r\nBreakout,0.000\r\n",
            $files['Hotlaps.dat'],
        );
    }

    public function test_selected_races_download_as_one_zip(): void
    {
        $heat = Race::factory()->create(['name' => 'Heat 1', 'laps' => 8]);
        $feature = Race::factory()->create(['name' => 'A-Main', 'laps' => 30]);
        $heat->syncLineup([
            Competitor::factory()->create(['transponder' => '111', 'car_number' => '1', 'last_name' => 'First', 'first_name' => 'Ann'])->id,
        ]);
        $feature->syncLineup([
            Competitor::factory()->create(['transponder' => '222', 'car_number' => '2', 'last_name' => 'Second', 'first_name' => 'Bob'])->id,
        ]);

        $response = $this->get(route('exports.timing-zip', ['races' => $heat->id.','.$feature->id]))->assertOk();
        $response->assertDownload('Timing files.zip');

        $files = $this->zipFiles($response);
        $this->assertSame(['Heat 1.csv', 'Heat 1.dat', 'A-Main.csv', 'A-Main.dat'], array_keys($files));
        $this->assertSame("111,1,First,Ann,,\r\n", $files['Heat 1.csv']);
        $this->assertSame("222,2,Second,Bob,,\r\n", $files['A-Main.csv']);
    }

    public function test_timing_zip_without_races_is_not_found(): void
    {
        $this->get(route('exports.timing-zip'))->assertNotFound();
        $this->get(route('exports.timing-zip', ['races' => '0']))->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function zipFiles(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $files[$name] = $zip->getFromIndex($index);
        }

        $zip->close();

        return $files;
    }
}
