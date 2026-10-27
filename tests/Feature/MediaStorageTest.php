<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\User;
use App\Models\VmixField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_headshots_are_stored_and_served_from_s3_when_configured(): void
    {
        config(['vmix.media_disk' => 's3']);
        Storage::fake('public');
        Storage::fake('s3');
        $driver = Competitor::factory()->create();

        $driver->storeHeadshot(UploadedFile::fake()->image('max.jpg', 600, 800));

        Storage::disk('s3')->assertExists($driver->headshot_path);
        Storage::disk('public')->assertMissing($driver->headshot_path);
        $this->assertSame(Storage::disk('s3')->url($driver->headshot_path), $driver->headshotUrl());
    }

    public function test_vmix_images_resize_from_the_original_on_s3(): void
    {
        config(['vmix.media_disk' => 's3']);
        Storage::fake('s3');
        $this->actingAs(User::factory()->create());
        $field = VmixField::factory()->image()->create();

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->set("uploads.{$field->id}", UploadedFile::fake()->image('logo.png', 1000, 500))
            ->set("fieldWidths.{$field->id}", '200')
            ->set("fieldHeights.{$field->id}", '200');

        $field->refresh();
        $image = imagecreatefromstring(Storage::disk('s3')->get($field->value));
        $this->assertSame([200, 200], [imagesx($image), imagesy($image)]);
        $this->assertSame(Storage::disk('s3')->url($field->value), $field->imageUrl());

        $component->call('removeImage', $field->id);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_copy_command_moves_existing_media_to_another_disk(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Storage::disk('public')->put('headshots/max.png', 'max');
        Storage::disk('public')->put('vmix/originals/logo.png', 'logo');
        Storage::disk('public')->put('other/skip.txt', 'not media');
        Storage::disk('s3')->put('vmix/originals/logo.png', 'already there');

        $this->artisan('media:copy', ['from' => 'public', 'to' => 's3'])
            ->expectsOutputToContain('Copied 1 file(s), skipped 1')
            ->assertSuccessful();

        $this->assertSame('max', Storage::disk('s3')->get('headshots/max.png'));
        $this->assertSame('already there', Storage::disk('s3')->get('vmix/originals/logo.png'));
        Storage::disk('s3')->assertMissing('other/skip.txt');
    }
}
