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
     * 1-based pool group this race uses when the type splits its cars.
     */
    public function poolGroupNumber(): int
    {
        $this->loadMissing(['raceType.cars', 'raceType.races']);

        return $this->raceType->groupNumberFor($this);
    }

    /**
     * Heat 1, Heat 2, … Heat 10 within each type, in the type's program order.
     *
     * @param  Collection<int, Race>  $races
     * @return Collection<int, Race>
     */
    public static function inProgramOrder(Collection $races): Collection
    {
        return $races
            ->sort(function (Race $a, Race $b): int {
                $aType = $a->raceType;
                $bType = $b->raceType;

                return $aType->sort_order <=> $bType->sort_order
                    ?: $aType->id <=> $bType->id
                    ?: strnatcasecmp($a->name, $b->name)
                    ?: $a->sort_order <=> $b->sort_order
                    ?: $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * Sponsor, then race name, the way it prints on a heat sheet (e.g. "ACME - HEAT 1").
     */
    public function printedTitle(): string
    {
        $name = strtoupper(trim($this->name));
        $sponsor = strtoupper(trim((string) $this->sponsor));

        return $sponsor !== '' ? $sponsor.' - '.$name : $name;
    }

    /**
     * Assigned invitational car number for the printed heat sheet.
     */
    public function kartAssignment(RaceEntry $entry): string
    {
        return $entry->car?->number ?? '';
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
