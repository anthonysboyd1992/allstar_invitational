<?php

namespace App\Models;

use Database\Factories\RaceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name', 'sort_order', 'cars_per_race', 'cars_allowed'])]
class RaceType extends Model
{
    /** @use HasFactory<RaceTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Race, $this>
     */
    public function races(): HasMany
    {
        return $this->hasMany(Race::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Heat 1, Heat 2, … Heat 10. Pool groups still follow {@see races()}.
     *
     * @return Collection<int, Race>
     */
    public function racesInProgramOrder(): Collection
    {
        $this->races->each(fn (Race $race) => $race->setRelation('raceType', $this));

        return Race::inProgramOrder($this->races);
    }

    /**
     * Invitational cars this type draws from, in pool order.
     *
     * @return BelongsToMany<Car, $this>
     */
    public function cars(): BelongsToMany
    {
        return $this->belongsToMany(Car::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('cars.id');
    }

    /**
     * How many cars each race draws. The full pool when cars_per_race is blank or not smaller than the pool.
     */
    public function groupSize(): int
    {
        $this->loadMissing('cars');
        $count = $this->cars->count();
        $perRace = $this->cars_per_race;

        if ($count === 0) {
            return 1;
        }

        return $perRace && $perRace > 0 && $perRace < $count ? $perRace : $count;
    }

    /**
     * Half the allowed (or current) pool, so a 10-car heat type alternates two groups of 5.
     */
    public function suggestedGroupSize(): int
    {
        $this->loadMissing('cars');
        $count = $this->cars_allowed ?: $this->cars->count();

        return $count >= 2 ? intdiv($count, 2) : 1;
    }

    public function poolIsFull(): bool
    {
        $this->loadMissing('cars');

        return $this->cars_allowed !== null && $this->cars->count() >= $this->cars_allowed;
    }

    /**
     * 0-based group this pool position falls into when the pool is split.
     */
    public function poolGroupIndex(int $position): int
    {
        $size = $this->groupSize();

        return $size > 0 ? intdiv($position, $size) : 0;
    }

    /**
     * Consecutive groups of groupSize() from the pool, in pool order.
     *
     * @return Collection<int, Collection<int, Car>>
     */
    public function carGroups(): Collection
    {
        $this->loadMissing('cars');
        $pool = $this->cars->values();

        if ($pool->isEmpty()) {
            return collect();
        }

        return $pool->chunk($this->groupSize())->values();
    }

    public function rotatesPool(): bool
    {
        return $this->carGroups()->count() > 1;
    }

    /**
     * 1-based group this race uses when the pool is split.
     */
    public function groupNumberFor(Race $race): int
    {
        $groups = $this->carGroups();
        $count = $groups->count();

        if ($count === 0) {
            return 1;
        }

        return ($this->raceIndex($race) % $count) + 1;
    }

    /**
     * Cars a race draws from the pool. When cars_per_race is smaller than the pool, groups rotate by race order.
     *
     * @return Collection<int, Car>
     */
    public function carsForRace(Race $race): Collection
    {
        $groups = $this->carGroups();

        if ($groups->isEmpty()) {
            return collect();
        }

        return $groups->get($this->raceIndex($race) % $groups->count())->values();
    }

    private function raceIndex(Race $race): int
    {
        $this->loadMissing('races');
        $index = $this->races->search(fn (Race $item) => $item->id === $race->id);

        return $index === false ? 0 : $index;
    }

    /**
     * Pair each race: heaviest driver to fastest car in that race's group.
     */
    public function assignCarsByWeight(): void
    {
        $this->unsetRelation('cars');
        $this->load(['cars', 'races']);

        $this->races->each(function (Race $race): void {
            $race->setRelation('raceType', $this);
            $race->assignCarsByWeight();
        });
    }

    /**
     * Rewrite pool sort_order to 0..n in the current ranking.
     */
    public function compactPoolOrder(): void
    {
        $this->cars()->get()
            ->each(fn (Car $car, int $index) => $car->pivot->sort_order === $index
                ? null
                : $this->cars()->updateExistingPivot($car->id, ['sort_order' => $index]));
    }

    /**
     * Give the 1st (fastest) car to group 1, the 2nd to group 2, and so on, then flatten back into pool order.
     */
    public function dealIntoGroups(): bool
    {
        $this->loadMissing('cars');

        if ($this->cars->count() < 2 || $this->carGroups()->count() < 2) {
            return false;
        }

        $this->sortPoolByTime();
        $this->unsetRelation('cars');
        $this->loadMissing('cars');

        $pool = $this->cars->values();
        $groupCount = $this->carGroups()->count();
        $buckets = array_fill(0, $groupCount, []);

        foreach ($pool as $index => $car) {
            $buckets[$index % $groupCount][] = $car;
        }

        $order = 0;

        foreach ($buckets as $bucket) {
            foreach ($bucket as $car) {
                $this->cars()->updateExistingPivot($car->id, ['sort_order' => $order]);
                $order++;
            }
        }

        $this->unsetRelation('cars');

        return true;
    }

    /**
     * Rank the pool fastest to slowest by lap time.
     */
    public function sortPoolByTime(): void
    {
        Car::sortFastestFirst($this->cars()->get())
            ->each(fn (Car $car, int $index) => $this->cars()->updateExistingPivot($car->id, ['sort_order' => $index]));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'cars_per_race' => 'integer',
            'cars_allowed' => 'integer',
        ];
    }
}
