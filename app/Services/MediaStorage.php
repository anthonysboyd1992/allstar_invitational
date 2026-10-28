<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where uploaded headshots, vMix images, and letterhead logos live: the local public disk by default, or S3 via VMIX_MEDIA_DISK.
 */
class MediaStorage
{
    public const Directories = ['headshots', 'vmix', 'letterhead'];

    public static function diskName(): string
    {
        return config('vmix.media_disk');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function url(string $path): string
    {
        return self::diskName() === 'public' ? asset('storage/'.$path) : self::disk()->url($path);
    }

    /**
     * Run the callback with a local copy of a stored file, since the image cropper needs a real file path.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    public static function withLocalCopy(string $path, callable $callback): mixed
    {
        $localPath = tempnam(sys_get_temp_dir(), 'media');

        try {
            file_put_contents($localPath, self::disk()->get($path));

            return $callback($localPath);
        } finally {
            @unlink($localPath);
        }
    }
}
