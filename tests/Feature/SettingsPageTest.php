<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_renders_letterhead_uploaders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('settings'))
            ->assertOk()
            ->assertSee('Letterhead logos')
            ->assertSee('Left (primary)')
            ->assertSee('Right');
    }

    public function test_uploading_a_left_logo_stores_a_png(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings')
            ->set('leftLogo', UploadedFile::fake()->image('allstar.jpg', 400, 200));

        $path = Setting::letterheadPath('left');
        $this->assertNotNull($path);
        $this->assertStringStartsWith('letterhead/', $path);
        Storage::disk('public')->assertExists($path);

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 200], [imagesx($image), imagesy($image)]);
        $this->assertSame(MediaStorage::url($path), Setting::letterheadUrl('left'));
    }

    public function test_replacing_and_removing_a_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $component = Livewire::test('pages::settings')
            ->set('rightLogo', UploadedFile::fake()->image('first.png', 100, 80));
        $firstPath = Setting::letterheadPath('right');

        $component->set('rightLogo', UploadedFile::fake()->image('second.png', 120, 80));
        $secondPath = Setting::letterheadPath('right');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $component->call('removeLogo', 'right');
        Storage::disk('public')->assertMissing($secondPath);
        $this->assertNull(Setting::letterheadPath('right'));
        $this->assertStringContainsString('images/letterhead-right.png', Setting::letterheadUrl('right'));
    }

    public function test_non_images_are_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings')
            ->set('leftLogo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertDispatched('notify');

        $this->assertNull(Setting::letterheadPath('left'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('settings'))->assertRedirect(route('login'));
    }

    public function test_timing_accounts_cannot_open_settings(): void
    {
        $this->actingAs(User::factory()->withAreas(User::Presets['timing']['areas'])->create());

        $this->get(route('settings'))->assertForbidden();
        Livewire::test('pages::settings')->assertForbidden();
    }
}
