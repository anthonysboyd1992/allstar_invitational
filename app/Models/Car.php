<?php

namespace App\Models;

use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['number', 'time_ms', 'sort_order'])]
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

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
