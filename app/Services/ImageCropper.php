<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageCropper
{
    /**
     * Scale the image to fill width x height, crop the overflow evenly from the center, and save it as a PNG on the disk.
     *
     * @return string the stored path
     */
    public function storeCover(string $sourcePath, int $width, int $height, string $directory, string $disk = 'public'): string
    {
        $path = trim($directory, '/').'/'.Str::random(40).'.png';

        Storage::disk($disk)->put($path, $this->cover($sourcePath, $width, $height));

        return $path;
    }

    /**
     * Re-encode as PNG, shrinking so the longest edge is at most $maxEdge.
     *
     * @return string the stored path
     */
    public function storePng(string $sourcePath, string $directory, string $disk = 'public', int $maxEdge = 1600): string
    {
        $path = trim($directory, '/').'/'.Str::random(40).'.png';

        Storage::disk($disk)->put($path, $this->png($this->fit($sourcePath, $maxEdge)));

        return $path;
    }

    /**
     * @return string PNG bytes
     */
    public function cover(string $sourcePath, int $width, int $height): string
    {
        $source = $this->load($sourcePath);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $cropWidth = (int) round($width / $scale);
        $cropHeight = (int) round($height / $scale);
        $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
        $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);

        $canvas = $this->transparentCanvas($width, $height);
        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $width, $height, $cropWidth, $cropHeight);

        return $this->png($canvas);
    }

    /**
     * @return string PNG bytes of a fully transparent image
     */
    public function transparent(int $width, int $height): string
    {
        return $this->png($this->transparentCanvas($width, $height));
    }

    private function transparentCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    /**
     * Scale down when the longest edge is over $maxEdge; otherwise return the original pixels.
     */
    private function fit(string $sourcePath, int $maxEdge): GdImage
    {
        $source = $this->load($sourcePath);
        $width = imagesx($source);
        $height = imagesy($source);
        $edge = max($width, $height);

        if ($edge <= $maxEdge) {
            return $source;
        }

        $scale = $maxEdge / $edge;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $canvas = $this->transparentCanvas($newWidth, $newHeight);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagesavealpha($canvas, true);

        return $canvas;
    }

    private function png(GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    private function load(string $sourcePath): GdImage
    {
        $contents = @file_get_contents($sourcePath);
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('That file could not be read as an image.');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);

        return $this->applyExifOrientation($image, $sourcePath);
    }

    /**
     * Phone photos are often stored sideways with an EXIF flag saying how to rotate them.
     */
    private function applyExifOrientation(GdImage $image, string $sourcePath): GdImage
    {
        if (! function_exists('exif_read_data') || @exif_imagetype($sourcePath) !== IMAGETYPE_JPEG) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($sourcePath)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
