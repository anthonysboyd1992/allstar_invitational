<?php

namespace App\Models;

use Database\Factories\RaceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name', 'sort_order', 'cars_per_race'])]
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
     * Cars a race draws from the pool. When cars_per_race is smaller than the pool, groups rotate by race order.
     *
     * @return Collection<int, Car>
     */
    public function carsForRace(Race $race): Collection
    {
        $this->loadMissing(['cars', 'races']);

        $pool = $this->cars->values();

        if ($pool->isEmpty()) {
            return $pool;
        }

        $perRace = $this->cars_per_race;
        $chunkSize = $perRace && $perRace > 0 && $perRace < $pool->count() ? $perRace : $pool->count();
        $groups = $pool->chunk($chunkSize)->values();
        $raceIndex = $this->races->search(fn (Race $item) => $item->id === $race->id);
        $raceIndex = $raceIndex === false ? 0 : $raceIndex;

        return $groups->get($raceIndex % $groups->count())->values();
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
        ];
    }
}
