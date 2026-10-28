<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceType;
use App\Models\Setting;
use App\Models\User;
use App\Services\VmixFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VmixFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_lineup_feed_matches_the_vmix_sample_exactly(): void
    {
        $race = Race::factory()
            ->for(RaceType::factory()->state(['name' => 'Heat']))
            ->create(['name' => 'Heat 18', 'laps' => 8, 'transfers' => 'Top 2 transfer']);

        $drivers = [
            ['5G', 'Gat', 'Leytham', 'Marshalltown IA'],
            ['4TW', 'Tim', 'Ward', 'Harcourt IA'],
            ['12J', 'Justin', "O'Brien", 'West Union IA'],
            ['20T', 'Terrance', 'Prochaska', 'Mason City IA'],
            ['76', 'Brad', 'Smith', 'Columbia Cross Roads PA'],
            ['41', 'Thor', 'Anderson', 'Monroe IA'],
            ['88Z', 'Zach', 'Fettinger', 'Klamath Falls OR'],
            ['20G', 'Dillon', 'Gentry', 'Ionia MI'],
        ];

        $race->syncLineup(array_map(fn (array $driver): int => Competitor::factory()->create([
            'car_number' => $driver[0],
            'first_name' => $driver[1],
            'last_name' => $driver[2],
            'hometown' => $driver[3],
        ])->id, $drivers));

        Setting::put(Setting::ActiveRace, (string) $race->id);
        Setting::put(Setting::ClassName, 'IMCA Modified');

        $response = $this->get(route('feeds.lineup'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/lineup-heat-18.json')), true);
        foreach (range(1, 12) as $slot) {
            $expected['Lineup'][0]["Headshot{$slot}.Source"] = VmixFeed::clearUrl(256, 256);
        }

        $this->assertSame($expected, json_decode($response->getContent(), true));
    }

    public function test_lineup_feed_pages_every_twelve_drivers_and_numbers_rows_across_pages(): void
    {
        $race = Race::factory()->create(['name' => 'A-Main']);
        $race->syncLineup(Competitor::factory()->count(14)->create()->pluck('id')->all());
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $pages = $this->getJson(route('feeds.lineup'))->json('Lineup');

        $this->assertCount(2, $pages);
        $this->assertSame(2, $pages[1]['TotalPages']);
        $this->assertSame(14, $pages[1]['TotalStarting.Text']);
        $this->assertSame('13', $pages[1]['StartPos1']);
        $this->assertArrayNotHasKey('Row1.Text', $pages[0]);
        $this->assertArrayNotHasKey('Row1Box.Source', $pages[0]);
    }

    public function test_the_vmix_page_turns_a_race_live(): void
    {
        $this->actingAs(User::factory()->create());
        $race = Race::factory()->create();

        Livewire::test('pages::vmix')->set('activeRaceId', (string) $race->id);

        $this->assertSame((string) $race->id, Setting::get(Setting::ActiveRace));
    }

    public function test_drivers_per_dataset_can_be_changed_on_the_vmix_page(): void
    {
        $this->actingAs(User::factory()->create());
        $race = Race::factory()->create();
        $race->syncLineup(Competitor::factory()->count(14)->create()->pluck('id')->all());
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $component = Livewire::test('pages::vmix')
            ->assertSet('driversPerDataset', '12')
            ->set('driversPerDataset', '6')
            ->assertSet('driversPerDataset', '6');

        $pages = $this->getJson(route('feeds.lineup'))->json('Lineup');
        $this->assertCount(3, $pages);
        $this->assertSame(3, $pages[2]['TotalPages']);
        $this->assertSame('13', $pages[2]['StartPos1']);
        $this->assertSame('', $pages[2]['StartPos3']);
        $this->assertArrayHasKey('Number6.Text', $pages[0]);
        $this->assertArrayNotHasKey('Number7.Text', $pages[0]);

        $component->set('driversPerDataset', '0')
            ->assertSet('driversPerDataset', '6')
            ->assertDispatched('notify');
        $this->assertSame(6, Setting::driversPerDataset());
    }

    public function test_lineup_feed_leaves_out_accolades(): void
    {
        $race = Race::factory()->create();
        $race->syncLineup([Competitor::factory()->create(['accolades' => '2025 Champion'])->id]);
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $page = $this->getJson(route('feeds.lineup'))->json('Lineup.0');

        $this->assertArrayNotHasKey('Accolades1.Text', $page);
        $this->assertArrayNotHasKey('Accolades2.Text', $page);
    }

    public function test_lineup_feed_returns_an_empty_page_when_no_race_is_live(): void
    {
        $page = $this->getJson(route('feeds.lineup'))->assertOk()->json('Lineup.0');

        $this->assertSame('', $page['Race.Text']);
        $this->assertSame(0, $page['TotalStarting.Text']);
        $this->assertSame('', $page['Number1.Text']);
        $this->assertSame('', $page['Driver1CarAssignment']);
    }

    public function test_lineup_feed_includes_kart_assignment_for_each_driver(): void
    {
        $type = RaceType::factory()->create();
        $race = Race::factory()->for($type)->create();
        $fast = Car::factory()->create(['number' => '01', 'time_ms' => 10000]);
        $slow = Car::factory()->create(['number' => '99', 'time_ms' => 12000]);
        $type->cars()->attach($fast->id, ['sort_order' => 0]);
        $type->cars()->attach($slow->id, ['sort_order' => 1]);
        $heavy = Competitor::factory()->create(['weight' => 200.0]);
        $light = Competitor::factory()->create(['weight' => 140.0]);
        $race->syncLineup([$heavy->id, $light->id]);
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $page = $this->getJson(route('feeds.lineup'))->json('Lineup.0');

        $this->assertSame('01', $page['Driver1CarAssignment']);
        $this->assertSame('99', $page['Driver2CarAssignment']);
        $this->assertSame('', $page['Driver3CarAssignment']);
    }

    public function test_every_image_source_is_set_even_for_empty_spots(): void
    {
        $race = Race::factory()->create();
        $race->syncLineup(Competitor::factory()->count(3)->create()->pluck('id')->all());
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $page = $this->getJson(route('feeds.lineup'))->json('Lineup.0');
        $sources = array_filter($page, fn (string $key): bool => str_ends_with($key, '.Source'), ARRAY_FILTER_USE_KEY);

        $this->assertSame(array_map(fn (int $slot): string => "Headshot{$slot}.Source", range(1, 12)), array_keys($sources));
        $this->assertNotContains('', $sources);
        $this->assertSame(VmixFeed::clearUrl(256, 256), $page['Headshot4.Source']);

        $driverFeed = $this->getJson(route('feeds.driver'))->json('0');
        $this->assertSame(VmixFeed::clearUrl(256, 256), $driverFeed['Headshot.Source']);
    }

    public function test_driver_feed_is_a_single_row_without_a_wrapper(): void
    {
        $race = Race::factory()->create(['name' => 'Heat 4']);
        $driver = Competitor::factory()->create([
            'car_number' => '222',
            'first_name' => 'Scott',
            'last_name' => 'Drake',
            'hometown' => 'Diamond MO',
            'accolades' => '2025 Champion',
        ]);

        Setting::put(Setting::ActiveRace, (string) $race->id);
        Setting::put(Setting::ActiveDriver, (string) $driver->id);
        Setting::put(Setting::ClassName, 'IMCA Modified');

        $response = $this->get(route('feeds.driver'))->assertOk();

        $this->assertSame([[
            'Class.Text' => 'IMCA Modified',
            'Race.Text' => 'Heat 4',
            'Event.Text' => 'IMCA Modified Heat 4',
            'Driver.Text' => '222',
            'Headshot.Source' => VmixFeed::clearUrl(256, 256),
            'FullName.Text' => 'Scott Drake',
            'Hometown.Text' => 'Diamond MO',
            'Accolades.Text' => '2025 Champion',
            'Mode' => 'name_only',
        ]], json_decode($response->getContent(), true));
    }

    public function test_clear_image_is_a_transparent_png_of_the_requested_size(): void
    {
        $response = $this->get(route('feeds.clear', ['w' => 543, 'h' => 97]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $image = imagecreatefromstring($response->getContent());
        $this->assertSame([543, 97], [imagesx($image), imagesy($image)]);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 271, 48))['alpha']);

        $oversized = imagecreatefromstring($this->get(route('feeds.clear', ['w' => 99999, 'h' => 0]))->getContent());
        $this->assertSame([4096, 1], [imagesx($oversized), imagesy($oversized)]);
    }

    public function test_lineup_images_all_link_to_this_server(): void
    {
        $race = Race::factory()->create();
        $race->syncLineup(Competitor::factory()->count(3)->create()->pluck('id')->all());
        Setting::put(Setting::ActiveRace, (string) $race->id);

        $page = $this->getJson(route('feeds.lineup'))->json('Lineup.0');

        foreach ($page as $key => $value) {
            if (str_ends_with($key, '.Source')) {
                $this->assertStringStartsWith(url('/'), $value, $key);
            }
        }
    }
}
