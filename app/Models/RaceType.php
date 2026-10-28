<?php

namespace App\Models;

use App\Services\TransferCalculator;
use Database\Factories\RaceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name', 'sort_order', 'cars_per_race', 'cars_allowed', 'combines_groups', 'assigns_by_pill', 'has_results'])]
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
     * Every occupied seat in this type has a finish, so Calculate can run.
     */
    public function resultsAreComplete(): bool
    {
        $this->loadMissing('races.entries');
        $occupied = $this->racesInProgramOrder()
            ->filter(fn (Race $race): bool => $race->entries->isNotEmpty());

        if ($occupied->isEmpty()) {
            return false;
        }

        return $occupied->every(
            fn (Race $race): bool => $race->entries->every(
                fn (RaceEntry $entry): bool => $entry->finish_position !== null,
            ),
        );
    }

    /**
     * Race this finisher transfers into, or null when the finish is blank or unmatched.
     */
    public function transferLabelFor(RaceEntry $entry): ?string
    {
        return (new TransferCalculator)->destinationLabel($this, $entry);
    }

    /**
     * How finishers from this type fill later races.
     *
     * @return HasMany<RaceTypeTransferRule, $this>
     */
    public function transferRules(): HasMany
    {
        return $this->hasMany(RaceTypeTransferRule::class)->orderBy('sort_order')->orderBy('id');
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
     * How many pool groups Alternate splits into. Uses the allowed cap so G1/G2 exist as cars are added.
     */
    public function groupCount(): int
    {
        $this->loadMissing('cars');
        $perRace = $this->cars_per_race;

        if (! $perRace || $perRace <= 0) {
            return 1;
        }

        $capacity = $this->cars_allowed ?: $this->cars->count();

        if ($capacity <= $perRace) {
            return 1;
        }

        return (int) ceil($capacity / $perRace);
    }

    /**
     * 0-based group this pool position falls into when the pool is split.
     */
    public function poolGroupIndex(int $position): int
    {
        $count = $this->groupCount();

        return $count > 0 ? $position % $count : 0;
    }

    /**
     * Pool groups in add order: 1st car G1, 2nd G2, and so on when Alternate is on.
     *
     * @return Collection<int, Collection<int, Car>>
     */
    public function carGroups(): Collection
    {
        $this->loadMissing('cars');
        $pool = $this->cars->values();
        $groupCount = $this->groupCount();

        if ($groupCount <= 1) {
            return $pool->isEmpty() ? collect() : collect([$pool]);
        }

        $buckets = array_fill(0, $groupCount, []);

        foreach ($pool as $index => $car) {
            $buckets[$index % $groupCount][] = $car;
        }

        return collect($buckets)->map(fn (array $cars) => collect($cars)->values())->values();
    }

    public function rotatesPool(): bool
    {
        return $this->groupCount() > 1;
    }

    /**
     * 1-based group this race uses when the pool is split.
     */
    public function groupNumberFor(Race $race): int
    {
        $count = $this->groupCount();

        if ($count === 0) {
            return 1;
        }

        return ($this->raceIndex($race) % $count) + 1;
    }

    /**
     * Cars a race draws from the pool. When Alternate is on, groups rotate by race order.
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
     * How many drivers go in each race for a pill draw: cars on track, or cars_per_race when the pool is still empty.
     */
    public function lineupGroupSize(): int
    {
        $this->loadMissing('cars');

        if ($this->cars->isNotEmpty()) {
            return $this->groupSize();
        }

        return $this->cars_per_race && $this->cars_per_race > 0 ? $this->cars_per_race : 1;
    }

    /**
     * Deal every driver across this type's races: lowest pill to race 1, next to race 2, and so on.
     */
    public function assignByPillDraw(): int
    {
        $this->loadMissing(['cars', 'races']);
        $races = $this->racesInProgramOrder();

        if ($races->isEmpty()) {
            return -1;
        }

        $drivers = Competitor::sortByPillNumber(Competitor::query()->get());

        if ($drivers->isEmpty()) {
            return 0;
        }

        $this->update(['assigns_by_pill' => true]);
        $this->unsetRelation('races');
        $this->load(['cars', 'races']);
        $races = $this->racesInProgramOrder();

        $buckets = array_fill(0, $races->count(), []);

        foreach ($drivers as $index => $driver) {
            $buckets[$index % $races->count()][] = $driver;
        }

        foreach ($races as $index => $race) {
            $race->setRelation('raceType', $this);
            $race->syncLineup(collect($buckets[$index])->pluck('id')->all());
        }

        return $races->count();
    }

    /**
     * Lineup rows for the timing page, in program order across every group.
     *
     * @return Collection<int, RaceEntry>
     */
    public function timingEntries(): Collection
    {
        $this->loadMissing(['races.entries.competitor']);

        return $this->racesInProgramOrder()->flatMap->entries->values();
    }

    /**
     * Drivers for one timing CSV, in program order across every group.
     *
     * @return Collection<int, Competitor>
     */
    public function transponderLineup(): Collection
    {
        return $this->timingEntries()
            ->map(fn (RaceEntry $entry): ?Competitor => $entry->competitor)
            ->filter(fn (?Competitor $competitor): bool => filled($competitor?->transponder))
            ->values();
    }

    /**
     * Drivers in this type who are on a lineup but have no transponder.
     */
    public function missingTransponderCount(): int
    {
        $this->loadMissing(['races.entries.competitor']);

        return $this->races
            ->flatMap->entries
            ->unique('competitor_id')
            ->filter(fn ($entry): bool => blank($entry->competitor?->transponder))
            ->count();
    }

    /**
     * Laps used when this type shares one timing file.
     */
    public function sessionLaps(): ?int
    {
        $this->loadMissing('races');

        return $this->racesInProgramOrder()->first()?->laps;
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

    private function ensureRaceCount(int $needed): void
    {
        $existing = $this->races->count();
        $nextOrder = (int) $this->races->max('sort_order');

        for ($number = $existing + 1; $number <= $needed; $number++) {
            $this->races()->create([
                'name' => "{$this->name} {$number}",
                'sort_order' => ++$nextOrder,
            ]);
        }
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
            'combines_groups' => 'boolean',
            'assigns_by_pill' => 'boolean',
            'has_results' => 'boolean',
        ];
    }
}
