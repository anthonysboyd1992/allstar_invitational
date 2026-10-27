<?php

namespace App\Models;

use App\Services\ImageCropper;
use App\Services\MediaStorage;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

#[Fillable(['pill_number', 'car_number', 'first_name', 'last_name', 'hometown', 'weight', 'accolades', 'transponder', 'headshot_path'])]
class Competitor extends Model
{
    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

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
        return $this->headshot_path ? MediaStorage::url($this->headshot_path) : null;
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
            MediaStorage::diskName(),
        );

        $this->deleteHeadshot();
        $this->update(['headshot_path' => $path]);
    }

    public function deleteHeadshot(): void
    {
        if ($this->headshot_path) {
            MediaStorage::disk()->delete($this->headshot_path);
        }

        $this->forceFill(['headshot_path' => null]);
    }

    /**
     * Order by car number the way people read them (9, 10, 71, 71H), then last name, with no car number last.
     *
     * @template TCollection of \Illuminate\Support\Collection<int, Competitor>
     *
     * @param  TCollection  $competitors
     * @return TCollection
     */
    public static function sortByCarNumber(Collection $competitors): Collection
    {
        return $competitors->sort(fn (Competitor $a, Competitor $b): int => (blank($a->car_number) <=> blank($b->car_number))
            ?: strnatcasecmp((string) $a->car_number, (string) $b->car_number)
            ?: strcasecmp((string) $a->last_name, (string) $b->last_name)
            ?: strcasecmp((string) $a->first_name, (string) $b->first_name))->values();
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
     * "#0 Last First" in lookups. Car number 0 is a real number; only a missing number is omitted.
     */
    public function labeledName(): string
    {
        return filled($this->car_number) ? '#'.$this->car_number.' '.$this->full_name : $this->full_name;
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
            'weight' => 'decimal:1',
        ];
    }
}
