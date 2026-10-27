<?php

namespace App\Models;

use App\Services\ImageCropper;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

#[Fillable(['pill_number', 'car_number', 'first_name', 'last_name', 'hometown', 'weight', 'accolades', 'transponder', 'headshot_path'])]
class Competitor extends Model
{
    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

    public const HeadshotDisk = 'public';

    public const HeadshotDirectory = 'headshots';

    public const HeadshotRules = ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'];

    /**
     * Validate and save a headshot upload, returning an error message instead of throwing when it can't be used.
     */
    public function replaceHeadshot(mixed $photo): ?string
    {
        $validator = Validator::make(['headshot' => $photo], ['headshot' => ['required', ...self::HeadshotRules]]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        try {
            $this->storeHeadshot($photo);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Public URL of the uploaded headshot, or null when none has been uploaded.
     */
    public function headshotUrl(): ?string
    {
        return $this->headshot_path ? asset('storage/'.$this->headshot_path) : null;
    }

    /**
     * Resize and center-crop the photo to the configured headshot size, replacing any previous headshot.
     */
    public function storeHeadshot(UploadedFile $photo): void
    {
        $path = app(ImageCropper::class)->storeCover(
            $photo->getRealPath(),
            config('vmix.headshot_width'),
            config('vmix.headshot_height'),
            self::HeadshotDirectory,
            self::HeadshotDisk,
        );

        $this->deleteHeadshot();
        $this->update(['headshot_path' => $path]);
    }

    public function deleteHeadshot(): void
    {
        if ($this->headshot_path) {
            Storage::disk(self::HeadshotDisk)->delete($this->headshot_path);
        }

        $this->forceFill(['headshot_path' => null]);
    }

    /**
     * @return HasMany<RaceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Key used by graphics to find the driver's headshot, e.g. "LeythamGat5GIMCAModified".
     */
    public function driverKey(string $className): string
    {
        return preg_replace('/\s+/', '', $this->last_name.$this->first_name.$this->car_number.$className);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pill_number' => 'integer',
            'weight' => 'integer',
        ];
    }
}
