<?php

namespace App\Models;

use App\Services\ImageCropper;
use App\Services\VmixFeed;
use Database\Factories\VmixFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * A field has two values: "value" is what is being edited and "live_value" is what the feed sends to vMix.
 * Fields set to "needs_update" only copy value to live_value when Update is pushed; the rest go live as they save.
 */
#[Fillable(['vmix_group_id', 'key', 'type', 'value', 'live_value', 'needs_update', 'width', 'height', 'original_path', 'sort_order'])]
class VmixField extends Model
{
    /** @use HasFactory<VmixFieldFactory> */
    use HasFactory;

    public const TypeText = 'text';

    public const TypeImage = 'image';

    public const TypeDriver = 'driver';

    public const Types = [self::TypeText => 'Text', self::TypeImage => 'Image', self::TypeDriver => 'Driver'];

    /**
     * Keys a driver field expands to in the feed, as "{field key}_{suffix}".
     */
    public const DriverKeySuffixes = ['number', 'name', 'first_name', 'last_name', 'hometown', 'accolades', 'headshot'];

    public const ImageDisk = 'public';

    public const ImageDirectory = 'vmix';

    public const DefaultWidth = 1920;

    public const DefaultHeight = 1080;

    public const MaxDimension = 4096;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_update' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VmixGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(VmixGroup::class, 'vmix_group_id');
    }

    public function isImage(): bool
    {
        return $this->type === self::TypeImage;
    }

    public function isDriver(): bool
    {
        return $this->type === self::TypeDriver;
    }

    /**
     * Whether the edited value differs from what is on air and is waiting for Update.
     */
    public function isPending(): bool
    {
        return $this->needs_update && $this->value !== $this->live_value;
    }

    /**
     * The key/value pairs this field sends to vMix. A driver field expands to one key per driver detail.
     *
     * @param  Collection<int, Competitor>  $drivers  competitors keyed by id
     * @return array<string, string>
     */
    public function feedValues(Collection $drivers): array
    {
        if ($this->isImage()) {
            return [$this->key => $this->imageUrl($this->live_value) ?? config('vmix.graphics_url').'clear.png?v='.config('vmix.asset_version')];
        }

        if (! $this->isDriver()) {
            return [$this->key => (string) $this->live_value];
        }

        $driver = $drivers->get((int) $this->live_value);

        $values = [
            'number' => (string) $driver?->car_number,
            'name' => (string) $driver?->full_name,
            'first_name' => (string) $driver?->first_name,
            'last_name' => (string) $driver?->last_name,
            'hometown' => (string) $driver?->hometown,
            'accolades' => (string) $driver?->accolades,
            'headshot' => app(VmixFeed::class)->headshotFor($driver),
        ];

        return collect(self::DriverKeySuffixes)
            ->mapWithKeys(fn (string $suffix) => ["{$this->key}_{$suffix}" => $values[$suffix]])
            ->all();
    }

    /**
     * Public URL of an uploaded image path (the edited one by default), or null when there is none.
     */
    public function imageUrl(?string $path = null): ?string
    {
        $path = func_num_args() ? $path : $this->value;

        return $this->isImage() && filled($path) ? asset('storage/'.$path) : null;
    }

    /**
     * Save an edited value. It goes on air immediately unless the field waits for Update.
     */
    public function saveValue(?string $value, array $extra = []): void
    {
        $previousFiles = $this->imagePaths();

        $this->update([
            ...$extra,
            'value' => $value,
            ...($this->needs_update ? [] : ['live_value' => $value]),
        ]);

        $this->deleteUnusedImages($previousFiles);
    }

    /**
     * Put the edited value on air.
     */
    public function pushLive(): void
    {
        $previousFiles = $this->imagePaths();

        $this->update(['live_value' => $this->value]);

        $this->deleteUnusedImages($previousFiles);
    }

    /**
     * Switch between waiting for Update and going live as it saves. Switching to live pushes any pending change.
     */
    public function setNeedsUpdate(bool $needsUpdate): void
    {
        $this->update(['needs_update' => $needsUpdate]);

        if (! $needsUpdate) {
            $this->pushLive();
        }
    }

    /**
     * Resize and center-crop the upload to this field's size, keeping the original so a later size change can re-crop it.
     */
    public function storeImage(UploadedFile $upload): void
    {
        $croppedPath = $this->cropFrom($upload->getRealPath());

        $this->saveValue($croppedPath, ['original_path' => $upload->store(self::ImageDirectory.'/originals', self::ImageDisk)]);
    }

    /**
     * Change the size and re-crop the kept original to match.
     */
    public function resize(int $width, int $height): void
    {
        $this->update(['width' => $width, 'height' => $height]);

        $disk = Storage::disk(self::ImageDisk);
        $sourcePath = collect([$this->original_path, $this->value])->first(fn (?string $path) => filled($path) && $disk->exists($path));

        if ($this->isImage() && $sourcePath) {
            $this->saveValue($this->cropFrom($disk->path($sourcePath)));
        }
    }

    public function clearImage(): void
    {
        $this->saveValue(null, ['original_path' => null]);
    }

    /**
     * Delete every stored file for this field, e.g. before the field itself is deleted.
     */
    public function deleteImageFiles(): void
    {
        if ($this->isImage()) {
            Storage::disk(self::ImageDisk)->delete($this->imagePaths());
        }
    }

    /**
     * @return list<string>
     */
    private function imagePaths(): array
    {
        return $this->isImage() ? array_values(array_unique(array_filter([$this->value, $this->live_value, $this->original_path]))) : [];
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteUnusedImages(array $paths): void
    {
        $unused = array_diff($paths, $this->imagePaths());

        if ($unused !== []) {
            Storage::disk(self::ImageDisk)->delete(array_values($unused));
        }
    }

    private function cropFrom(string $sourcePath): string
    {
        return app(ImageCropper::class)->storeCover(
            $sourcePath,
            $this->width ?: self::DefaultWidth,
            $this->height ?: self::DefaultHeight,
            self::ImageDirectory,
            self::ImageDisk,
        );
    }
}
