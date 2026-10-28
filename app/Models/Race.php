<?php

namespace App\Models;

use Database\Factories\RaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['race_type_id', 'name', 'laps', 'transfers', 'sponsor', 'sort_order'])]
class Race extends Model
{
    /** @use HasFactory<RaceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RaceType, $this>
     */
    public function raceType(): BelongsTo
    {
        return $this->belongsTo(RaceType::class);
    }

    /**
     * @return HasMany<RaceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(RaceEntry::class)->orderBy('start_position');
    }

    /**
     * @return list<int>
     */
    public function lineupIds(): array
    {
        return $this->entries()->pluck('competitor_id')->all();
    }

    /**
     * Replace the lineup with the given competitors, in starting order.
     *
     * @param  list<int>  $competitorIds
     */
    public function syncLineup(array $competitorIds): void
    {
        $competitorIds = array_values(array_unique(array_map('intval', $competitorIds)));

        DB::transaction(function () use ($competitorIds): void {
            $this->entries()->whereNotIn('competitor_id', $competitorIds)->delete();

            foreach ($competitorIds as $index => $competitorId) {
                $this->entries()->updateOrCreate(
                    ['competitor_id' => $competitorId],
                    ['start_position' => $index + 1],
                );
            }
        });

        $this->unsetRelation('entries');
        $this->assignCarsByWeight();
    }

    /**
     * Cars this race draws from its type's pool.
     *
     * @return Collection<int, Car>
     */
    public function poolCars(): Collection
    {
        $this->loadMissing(['raceType.cars', 'raceType.races']);

        return $this->raceType->carsForRace($this);
    }

    /**
     * Pair drivers in this race: heaviest to the fastest car from the type's group for this race.
     */
    public function assignCarsByWeight(): void
    {
        $this->loadMissing(['entries.competitor', 'raceType.cars', 'raceType.races']);

        $cars = Car::sortFastestFirst($this->raceType->carsForRace($this));
        $entries = $this->entriesByWeight();

        foreach ($entries as $index => $entry) {
            $carId = $cars->get($index)?->id;

            if ($entry->car_id !== $carId) {
                $entry->update(['car_id' => $carId]);
            }
        }
    }

    /**
     * Heaviest driver first; missing weights last, then starting position.
     *
     * @return Collection<int, RaceEntry>
     */
    public function entriesByWeight(): Collection
    {
        $this->loadMissing('entries.competitor');

        return $this->entries
            ->sort(function (RaceEntry $a, RaceEntry $b): int {
                $aWeight = $a->competitor?->weight;
                $bWeight = $b->competitor?->weight;

                return ($aWeight === null) <=> ($bWeight === null)
                    ?: ((float) $bWeight <=> (float) $aWeight)
                    ?: $a->start_position <=> $b->start_position;
            })
            ->values();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'laps' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
