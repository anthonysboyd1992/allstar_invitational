<?php

namespace Tests\Feature;

use App\Services\ImageCropper;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageCropperTest extends TestCase
{
    public function test_it_fills_the_size_and_crops_evenly_from_the_center(): void
    {
        Storage::fake('public');

        $path = app(ImageCropper::class)->storeCover($this->stripedImage(), 50, 50, 'crops');

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.png', $path);

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame(50, imagesx($image));
        $this->assertSame(50, imagesy($image));

        foreach ([[0, 0], [25, 25], [49, 49]] as [$x, $y]) {
            $this->assertSame([0, 255, 0], $this->rgbAt($image, $x, $y), "Pixel {$x},{$y} should come from the green middle stripe.");
        }
    }

    public function test_it_scales_up_small_images(): void
    {
        $image = imagecreatefromstring(app(ImageCropper::class)->cover($this->stripedImage(), 600, 100));

        $this->assertSame(600, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame([255, 0, 0], $this->rgbAt($image, 10, 50));
        $this->assertSame([0, 0, 255], $this->rgbAt($image, 590, 50));
    }

    public function test_storing_a_png_keeps_the_original_aspect_ratio(): void
    {
        Storage::fake('public');

        $path = app(ImageCropper::class)->storePng($this->stripedImage(), 'letterhead');

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([300, 100], [imagesx($image), imagesy($image)]);
    }

    public function test_storing_a_png_shrinks_when_the_longest_edge_is_over_the_limit(): void
    {
        Storage::fake('public');

        $path = app(ImageCropper::class)->storePng($this->stripedImage(), 'letterhead', 'public', 150);

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([150, 50], [imagesx($image), imagesy($image)]);
    }

    /**
     * A 300x100 PNG with red, green and blue vertical stripes, each 100px wide.
     */
    private function stripedImage(): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 200, 0, 299, 99, imagecolorallocate($image, 0, 0, 255));

        $path = tempnam(sys_get_temp_dir(), 'stripes').'.png';
        imagepng($image, $path);

        return $path;
    }

    /**
     * @return array{int, int, int}
     */
    private function rgbAt(\GdImage $image, int $x, int $y): array
    {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$color['red'], $color['green'], $color['blue']];
    }
}
