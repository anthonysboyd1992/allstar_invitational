<?php

namespace App\Models;

use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['number', 'time_ms', 'sort_order'])]
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    /**
     * Race types that draw this car from their pool.
     *
     * @return BelongsToMany<RaceType, $this>
     */
    public function raceTypes(): BelongsToMany
    {
        return $this->belongsToMany(RaceType::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<RaceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class);
    }

    /**
     * Order by car number the way people read them (01, 1, 2, 4, 11, 4A).
     *
     * @template TCollection of \Illuminate\Support\Collection<int, Car>
     *
     * @param  TCollection  $cars
     * @return TCollection
     */
    public static function sortByNumber(Collection $cars): Collection
    {
        return $cars->sort(fn (Car $a, Car $b): int => (blank($a->number) <=> blank($b->number))
            ?: strnatcasecmp((string) $a->number, (string) $b->number)
            ?: strcmp((string) $a->number, (string) $b->number))->values();
    }

    /**
     * Fastest first: timed cars by time, then pool/manual order, then number.
     *
     * @template TCollection of \Illuminate\Support\Collection<int, Car>
     *
     * @param  TCollection  $cars
     * @return TCollection
     */
    public static function sortFastestFirst(Collection $cars): Collection
    {
        return $cars->sort(function (Car $a, Car $b): int {
            $byTime = ($a->time_ms === null) <=> ($b->time_ms === null)
                ?: ($a->time_ms ?? 0) <=> ($b->time_ms ?? 0);

            if ($byTime !== 0) {
                return $byTime;
            }

            $aOrder = $a->pivot?->sort_order ?? $a->sort_order;
            $bOrder = $b->pivot?->sort_order ?? $b->sort_order;

            return $aOrder <=> $bOrder
                ?: strnatcasecmp((string) $a->number, (string) $b->number)
                ?: strcmp((string) $a->number, (string) $b->number);
        })->values();
    }

    /**
     * Fastest time first; cars with no time last, then by car number.
     *
     * @template TCollection of \Illuminate\Support\Collection<int, Car>
     *
     * @param  TCollection  $cars
     * @return TCollection
     */
    public static function sortByTime(Collection $cars): Collection
    {
        return $cars->sort(function (Car $a, Car $b): int {
            $byTime = ($a->time_ms === null) <=> ($b->time_ms === null)
                ?: ($a->time_ms ?? 0) <=> ($b->time_ms ?? 0);

            return $byTime !== 0 ? $byTime : (strnatcasecmp((string) $a->number, (string) $b->number) ?: strcmp((string) $a->number, (string) $b->number));
        })->values();
    }

    /**
     * Rewrite sort_order to match car-number order.
     */
    public static function applyNumberOrder(): void
    {
        static::sortByNumber(static::query()->get())
            ->each(fn (Car $car, int $index) => $car->sort_order === $index ? null : $car->update(['sort_order' => $index]));
    }

    /**
     * Rewrite sort_order to match fastest-to-slowest time.
     */
    public static function applyTimeOrder(): void
    {
        static::sortByTime(static::query()->get())
            ->each(fn (Car $car, int $index) => $car->sort_order === $index ? null : $car->update(['sort_order' => $index]));
    }

    /**
     * Compact sort_order to 0..n in the current ranking.
     */
    public static function compactSortOrder(): void
    {
        static::query()->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn (Car $car, int $index) => $car->sort_order === $index ? null : $car->update(['sort_order' => $index]));
    }

    /**
     * Parse a lap time like 12.345 or 1:14.567 into milliseconds. Empty is null; invalid is false.
     */
    public static function parseTime(?string $value): int|false|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):([0-5]?\d)(?:\.(\d{1,3}))?$/', $value, $match)) {
            return (((int) $match[1] * 60) + (int) $match[2]) * 1000 + (int) str_pad($match[3] ?? '', 3, '0');
        }

        if (preg_match('/^(\d{1,4})(?:\.(\d{1,3}))?$/', $value, $match)) {
            return ((int) $match[1] * 1000) + (int) str_pad($match[2] ?? '', 3, '0');
        }

        return false;
    }

    public static function formatTime(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '';
        }

        $minutes = intdiv($milliseconds, 60000);
        $seconds = intdiv($milliseconds % 60000, 1000);
        $fraction = str_pad((string) ($milliseconds % 1000), 3, '0', STR_PAD_LEFT);

        if ($minutes > 0) {
            return $minutes.':'.str_pad((string) $seconds, 2, '0', STR_PAD_LEFT).'.'.$fraction;
        }

        return $seconds.'.'.$fraction;
    }

    /**
     * FAST / MID FAST / MID / SLOWER / SLOWEST for a 0-based rank in a group of $count cars.
     */
    public static function speedLabel(int $rank, int $count): string
    {
        if ($count <= 1 || $rank <= 0) {
            return 'FAST';
        }

        if ($rank >= $count - 1) {
            return 'SLOWEST';
        }

        $labels = match ($count) {
            3 => ['FAST', 'MID', 'SLOWEST'],
            4 => ['FAST', 'MID FAST', 'SLOWER', 'SLOWEST'],
            5 => ['FAST', 'MID FAST', 'MID', 'SLOWER', 'SLOWEST'],
            default => null,
        };

        if ($labels !== null) {
            return $labels[$rank];
        }

        $ratio = $rank / ($count - 1);

        return match (true) {
            $ratio < 0.34 => 'MID FAST',
            $ratio < 0.67 => 'MID',
            default => 'SLOWER',
        };
    }

    public function formattedTime(): string
    {
        return static::formatTime($this->time_ms);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_ms' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
