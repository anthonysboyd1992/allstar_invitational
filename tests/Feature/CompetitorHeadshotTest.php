<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Race;
use App\Models\Setting;
use App\Models\User;
use App\Services\VmixFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitorHeadshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['vmix.headshot_width' => 120, 'vmix.headshot_height' => 150]);
        $this->actingAs(User::factory()->create());
    }

    public function test_uploading_from_the_table_crops_to_the_headshot_size(): void
    {
        $competitor = Competitor::factory()->create();

        Livewire::test('pages::competitors')
            ->set("headshotUploads.{$competitor->id}", UploadedFile::fake()->image('photo.jpg', 800, 400));

        $path = $competitor->refresh()->headshot_path;
        $this->assertStringStartsWith('headshots/', $path);

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([120, 150], [imagesx($image), imagesy($image)]);
    }

    public function test_replacing_and_removing_a_headshot_deletes_the_old_file(): void
    {
        $competitor = Competitor::factory()->create();
        $component = Livewire::test('pages::competitors')
            ->set("headshotUploads.{$competitor->id}", UploadedFile::fake()->image('first.png'));
        $firstPath = $competitor->refresh()->headshot_path;

        $component->set("headshotUploads.{$competitor->id}", UploadedFile::fake()->image('second.png'));
        $secondPath = $competitor->refresh()->headshot_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);

        $component->call('removeHeadshot', $competitor->id);
        Storage::disk('public')->assertMissing($secondPath);
        $this->assertNull($competitor->refresh()->headshot_path);
    }

    public function test_non_images_are_rejected(): void
    {
        $competitor = Competitor::factory()->create();

        Livewire::test('pages::competitors')
            ->set("headshotUploads.{$competitor->id}", UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertDispatched('notify');

        $this->assertNull($competitor->refresh()->headshot_path);
    }

    public function test_adding_a_competitor_with_a_headshot_from_the_modal(): void
    {
        Livewire::test('pages::competitors')
            ->call('openAddModal')
            ->set('form.first_name', 'Gat')
            ->set('form.last_name', 'Leytham')
            ->set('headshot', UploadedFile::fake()->image('gat.png', 300, 300))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('headshot', null);

        $competitor = Competitor::query()->sole();
        Storage::disk('public')->assertExists($competitor->headshot_path);
    }

    public function test_deleting_a_competitor_deletes_the_headshot(): void
    {
        $competitor = Competitor::factory()->create();
        $component = Livewire::test('pages::competitors')
            ->set("headshotUploads.{$competitor->id}", UploadedFile::fake()->image('photo.png'));
        $path = $competitor->refresh()->headshot_path;

        $component->call('delete', $competitor->id);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_vmix_page_can_replace_the_on_screen_drivers_headshot(): void
    {
        $driver = Competitor::factory()->create();
        Setting::put(Setting::ActiveDriver, (string) $driver->id);

        Livewire::test('pages::vmix')
            ->set('driverHeadshot', UploadedFile::fake()->image('photo.png'))
            ->assertSet('driverHeadshot', null)
            ->assertDispatched('notify');

        Storage::disk('public')->assertExists($driver->refresh()->headshot_path);
        $this->assertSame(asset('storage/'.$driver->headshot_path), $this->getJson(route('feeds.driver'))->json('0')['Headshot.Source']);
    }

    public function test_uploaded_headshots_are_used_in_the_lineup_and_driver_feeds(): void
    {
        [$withPhoto, $withoutPhoto] = Competitor::factory()->count(2)->create();
        Livewire::test('pages::competitors')
            ->set("headshotUploads.{$withPhoto->id}", UploadedFile::fake()->image('photo.png'));
        $url = asset('storage/'.$withPhoto->refresh()->headshot_path);

        $race = Race::factory()->create();
        $race->syncLineup([$withPhoto->id, $withoutPhoto->id]);
        Setting::put(Setting::ActiveRace, (string) $race->id);
        Setting::put(Setting::ActiveDriver, (string) $withPhoto->id);

        $page = $this->getJson(route('feeds.lineup'))->json('Lineup.0');
        $this->assertSame($url, $page['Headshot1.Source']);
        $this->assertSame(asset(VmixFeed::ClearHeadshot), $page['Headshot2.Source']);

        $this->assertSame($url, $this->getJson(route('feeds.driver'))->json('0')['Headshot.Source']);
    }
}
