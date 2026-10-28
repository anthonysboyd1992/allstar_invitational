<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

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
}
