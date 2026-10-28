<?php

namespace App\Models;

use App\Services\ImageCropper;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const ActiveRace = 'active_race_id';

    public const ActiveDriver = 'active_competitor_id';

    public const ClassName = 'class_name';

    public const DriverMode = 'driver_mode';

    public const DriversPerDataset = 'lineup_drivers_per_dataset';

    /**
     * The vMix data run-of-show item Next / Previous step from.
     */
    public const ShowCurrentItem = 'vmix_show_current_item';

    /**
     * How the cars page is ordered: number, time, or manual (drag).
     */
    public const CarsSort = 'cars_sort';

    public const LetterheadLeft = 'letterhead_left';

    public const LetterheadRight = 'letterhead_right';

    public const LetterheadDirectory = 'letterhead';

    /**
     * @var list<string>
     */
    public const LetterheadRules = ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240'];

    /**
     * @var list<string>
     */
    public const LetterheadSides = ['left', 'right'];

    public const DefaultDriversPerDataset = 12;

    public const MaxDriversPerDataset = 50;

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function activeRace(): ?Race
    {
        $id = static::get(self::ActiveRace);

        return $id ? Race::query()->with(['raceType', 'entries.competitor', 'entries.car'])->find($id) : null;
    }

    /**
     * How many drivers go in each dataset (page) of the vMix lineup feed.
     */
    public static function driversPerDataset(): int
    {
        $count = (int) static::get(self::DriversPerDataset, (string) self::DefaultDriversPerDataset);

        return $count >= 1 && $count <= self::MaxDriversPerDataset ? $count : self::DefaultDriversPerDataset;
    }

    public static function activeDriver(): ?Competitor
    {
        $id = static::get(self::ActiveDriver);

        return $id ? Competitor::query()->find($id) : null;
    }

    /**
     * Stored path of an uploaded letterhead logo, or null when the built-in image should be used.
     */
    public static function letterheadPath(string $side): ?string
    {
        return static::get(static::letterheadKey($side));
    }

    /**
     * Public URL of the uploaded letterhead logo, or the built-in image when none has been uploaded.
     */
    public static function letterheadUrl(string $side): string
    {
        $path = static::letterheadPath($side);

        return $path ? MediaStorage::url($path) : asset('images/letterhead-'.$side.'.png');
    }

    /**
     * Validate and save a letterhead logo, returning an error message instead of throwing when it can't be used.
     */
    public static function replaceLetterhead(string $side, mixed $photo): ?string
    {
        $validator = Validator::make(['logo' => $photo], ['logo' => ['required', ...self::LetterheadRules]]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        try {
            static::storeLetterhead($side, $photo);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public static function storeLetterhead(string $side, UploadedFile $photo): void
    {
        $path = app(ImageCropper::class)->storePng(
            $photo->getRealPath(),
            self::LetterheadDirectory,
            MediaStorage::diskName(),
        );

        static::deleteLetterhead($side);
        static::put(static::letterheadKey($side), $path);
    }

    public static function deleteLetterhead(string $side): void
    {
        $path = static::letterheadPath($side);

        if ($path) {
            MediaStorage::disk()->delete($path);
        }

        static::put(static::letterheadKey($side), null);
    }

    public static function letterheadKey(string $side): string
    {
        return match ($side) {
            'left' => self::LetterheadLeft,
            'right' => self::LetterheadRight,
        };
    }
}
