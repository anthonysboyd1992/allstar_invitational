<?php

namespace App\Models;

use App\TimingStatus;
use Database\Factories\RaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['race_type_id', 'name', 'lineup_name', 'laps', 'transfers', 'sponsor', 'starting_positions', 'sort_order', 'timing_status'])]
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

    public function hasFixedField(): bool
    {
        return (int) $this->starting_positions > 0;
    }

    /**
     * How many starting spots to draw. A configured field can grow if a driver is already past that number.
     */
    public function slotCount(): int
    {
        $this->loadMissing('entries');
        $occupied = (int) $this->entries->max('start_position');

        if ($this->hasFixedField()) {
            return max((int) $this->starting_positions, $occupied);
        }

        return $this->entries->count();
    }

    /**
     * Lowest empty starting spot, or null when the field is full.
     */
    public function firstOpenSlot(): ?int
    {
        if (! $this->hasFixedField()) {
            return null;
        }

        $this->loadMissing('entries');
        $taken = $this->entries->pluck('start_position')->all();

        foreach (range(1, (int) $this->starting_positions) as $slot) {
            if (! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Next empty starting spot at or after $start. A start slot does not wrap back to 1.
     */
    public function nextOpenSlotFrom(?int $start): ?int
    {
        $this->loadMissing('entries');

        if (! $this->hasFixedField()) {
            return $this->entries->count() + 1;
        }

        $taken = $this->entries->pluck('start_position')->all();
        $from = $start && $start > 0 ? $start : 1;

        foreach (range($from, $this->slotCount()) as $slot) {
            if (! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        if ($start && $start > 1) {
            return null;
        }

        return $this->firstOpenSlot();
    }

    /**
     * Seat a transferred driver. Occupied spots are left alone.
     */
    public function placeTransfer(int $competitorId, int $slot, int $sourceRaceId): bool
    {
        $this->loadMissing('entries');

        if ($this->entries->contains(fn (RaceEntry $entry): bool => $entry->competitor_id === $competitorId)) {
            return false;
        }

        $slot = max(1, $slot);

        if ($this->hasFixedField()) {
            $slot = min($slot, $this->slotCount());
        }

        if ($this->entries->contains(fn (RaceEntry $entry): bool => $entry->start_position === $slot)) {
            return false;
        }

        $this->entries()->create([
            'competitor_id' => $competitorId,
            'start_position' => $slot,
            'source_race_id' => $sourceRaceId,
        ]);

        $this->unsetRelation('entries');
        $this->assignCarsByWeight();

        return true;
    }

    /**
     * Drop lineup seats this source type previously calculated onto this race.
     *
     * @param  list<int>  $sourceRaceIds
     */
    public function removeTransfersFrom(array $sourceRaceIds): void
    {
        if ($sourceRaceIds === []) {
            return;
        }

        $this->entries()->whereIn('source_race_id', $sourceRaceIds)->delete();
        $this->unsetRelation('entries');

        if (! $this->hasFixedField()) {
            $this->load('entries');
            foreach ($this->entries->values() as $index => $entry) {
                $entry->update(['start_position' => $index + 1]);
            }
            $this->unsetRelation('entries');
        }

        $this->assignCarsByWeight();
    }

    /**
     * Drivers still waiting to be clicked into finish order.
     *
     * @return Collection<int, RaceEntry>
     */
    public function unscoredEntries(): Collection
    {
        $this->loadMissing('entries.competitor');

        return $this->entries
            ->filter(fn (RaceEntry $entry): bool => $entry->finish_position === null)
            ->sortBy('start_position')
            ->values();
    }

    /**
     * Drivers already placed in finish order.
     *
     * @return Collection<int, RaceEntry>
     */
    public function scoredEntries(): Collection
    {
        $this->loadMissing('entries.competitor');

        return $this->entries
            ->filter(fn (RaceEntry $entry): bool => $entry->finish_position !== null)
            ->sortBy('finish_position')
            ->values();
    }

    /**
     * At least one driver has a finish, so this race is done for timing files.
     */
    public function hasResults(): bool
    {
        return $this->entries->contains(
            fn (RaceEntry $entry): bool => $entry->finish_position !== null,
        );
    }

    /**
     * Only Lineups status is written to the Timing File Feed.
     */
    public function isReadyForTimingPull(): bool
    {
        return $this->timing_status === TimingStatus::Lineups;
    }

    /**
     * One-file types share a timing session, so every group gets the same status.
     */
    public function setTimingStatus(TimingStatus $status): void
    {
        $this->loadMissing('raceType');

        if ($this->raceType->combines_groups) {
            $this->raceType->races()->update(['timing_status' => $status]);

            return;
        }

        $this->update(['timing_status' => $status]);
    }

    /**
     * Finish order with transfer labels for the Results board.
     *
     * @return Collection<int, array{entry: RaceEntry, transfer: ?string}>
     */
    public function scoredResultRows(): Collection
    {
        $this->loadMissing(['entries.competitor', 'raceType.transferRules.destination.races', 'raceType.races.entries']);

        return $this->scoredEntries()->map(fn (RaceEntry $entry): array => [
            'entry' => $entry,
            'transfer' => $this->raceType->transferLabelFor($entry),
        ]);
    }

    /**
     * Grid of starting spots. Empty spots stay empty until a driver is placed there.
     *
     * @return Collection<int, array{position: int, entry: ?RaceEntry}>
     */
    public function lineupSlots(): Collection
    {
        $this->loadMissing(['entries.competitor', 'entries.car']);

        if (! $this->hasFixedField()) {
            return $this->entries->values()->map(fn (RaceEntry $entry): array => [
                'position' => $entry->start_position,
                'entry' => $entry,
            ]);
        }

        $byPosition = $this->entries->keyBy('start_position');

        return collect(range(1, $this->slotCount()))
            ->map(fn (int $position): array => [
                'position' => $position,
                'entry' => $byPosition->get($position),
            ]);
    }

    /**
     * Put a driver in a specific starting spot. Occupied spots swap when the driver is already in this race.
     */
    public function placeInSlot(int $competitorId, int $slot): void
    {
        $this->loadMissing('entries');
        $max = $this->hasFixedField() ? $this->slotCount() : $slot;
        $slot = max(1, min($slot, $max));

        DB::transaction(function () use ($competitorId, $slot): void {
            $this->load('entries');
            $occupant = $this->entries->firstWhere('start_position', $slot);
            $moving = $this->entries->firstWhere('competitor_id', $competitorId);

            if ($moving && $occupant && $moving->id === $occupant->id) {
                return;
            }

            if ($occupant && ! $moving) {
                return;
            }

            if ($moving && $occupant) {
                $previous = $moving->start_position;
                $occupant->update(['start_position' => 10000 + $previous]);
                $moving->update(['start_position' => $slot]);
                $occupant->update(['start_position' => $previous]);

                return;
            }

            if ($moving) {
                $moving->update(['start_position' => $slot]);

                return;
            }

            $this->entries()->create([
                'competitor_id' => $competitorId,
                'start_position' => $slot,
            ]);
        });

        $this->unsetRelation('entries');
        $this->assignCarsByWeight();
    }

    /**
     * Take a driver off this race. Fixed fields keep the hole; packed lineups close the gap.
     */
    public function removeCompetitor(int $competitorId): void
    {
        if ($this->hasFixedField()) {
            $this->entries()->where('competitor_id', $competitorId)->delete();
            $this->unsetRelation('entries');
            $this->assignCarsByWeight();

            return;
        }

        $this->syncLineup(array_values(array_diff($this->lineupIds(), [$competitorId])));
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
     * 1-based place of this race in its type's program order.
     */
    public function groupNumber(): int
    {
        $this->loadMissing(['raceType.races']);
        $index = $this->raceType->racesInProgramOrder()->search(fn (Race $race): bool => $race->id === $this->id);

        return ($index === false ? 0 : $index) + 1;
    }

    /**
     * Heat-sheet heading: the race name, uppercase.
     */
    public function printedRaceName(): string
    {
        return strtoupper(trim($this->name));
    }

    /**
     * Sponsor, then race name, the way it prints on a heat sheet (e.g. "ACME - HEAT 1").
     */
    public function printedTitle(): string
    {
        return $this->printedHeading($this->name);
    }

    /**
     * Lineup print heading. A lineup override replaces the sponsor and race name.
     */
    public function printedLineupTitle(): string
    {
        if (filled($this->lineup_name)) {
            return strtoupper(trim($this->lineup_name));
        }

        return $this->printedHeading($this->name);
    }

    private function printedHeading(string $name): string
    {
        $heading = strtoupper(trim($name));
        $sponsor = strtoupper(trim((string) $this->sponsor));

        return $sponsor !== '' ? $sponsor.' - '.$heading : $heading;
    }

    /**
     * Printable tables for this race. Each race prints as its own group, including empty starting spots.
     *
     * @return Collection<int, array{title: string, slots: Collection<int, array{position: int, entry: ?RaceEntry, transfer: ?string}>, race: Race, show_pill: bool, show_weight: bool, show_transfer: bool, columns: int}>
     */
    public function printedLineupBlocks(): Collection
    {
        return $this->printedSheetBlocks(showWeight: false, showTransfer: false);
    }

    /**
     * Finish order with transfer destinations and charge (start minus finish).
     *
     * @return Collection<int, array{title: string, slots: Collection<int, array{position: int, entry: ?RaceEntry, transfer: ?string}>, race: Race, show_pill: bool, show_weight: bool, show_transfer: bool, columns: int}>
     */
    public function printedResultBlocks(): Collection
    {
        $this->loadMissing(['entries.competitor', 'entries.car', 'raceType.transferRules.destination.races', 'raceType.races.entries']);
        $slots = $this->entries
            ->filter(fn (RaceEntry $entry): bool => $entry->finish_position !== null)
            ->sortBy('finish_position')
            ->values()
            ->map(fn (RaceEntry $entry): array => [
                'position' => (int) $entry->finish_position,
                'entry' => $entry,
                'transfer' => $this->raceType->transferLabelFor($entry),
            ]);

        return collect([[
            'title' => $this->printedTitle(),
            'slots' => $slots,
            'race' => $this,
            'show_pill' => false,
            'show_weight' => false,
            'show_transfer' => true,
            'columns' => 8,
        ]]);
    }

    /**
     * @return Collection<int, array{title: string, slots: Collection<int, array{position: int, entry: ?RaceEntry, transfer: ?string}>, race: Race, show_pill: bool, show_weight: bool, show_transfer: bool, columns: int}>
     */
    private function printedSheetBlocks(bool $showWeight, bool $showTransfer): Collection
    {
        $this->loadMissing(['entries.competitor', 'entries.car', 'raceType']);
        $showPill = (bool) $this->raceType->assigns_by_pill;
        $slots = $this->lineupSlots()->map(fn (array $slot): array => [
            'position' => $slot['position'],
            'entry' => $slot['entry'],
            'transfer' => null,
        ]);

        return collect([[
            'title' => $this->printedLineupTitle(),
            'slots' => $slots,
            'race' => $this,
            'show_pill' => $showPill,
            'show_weight' => $showWeight,
            'show_transfer' => $showTransfer,
            'columns' => ($showPill ? 7 : 6) + ($showWeight ? 1 : 0) + ($showTransfer ? 1 : 0),
        ]]);
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

        $this->pairEntriesWithCars($this->entriesByWeight(), Car::sortFastestFirst($this->raceType->carsForRace($this)));
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
     * @param  Collection<int, RaceEntry>  $entries
     * @param  Collection<int, Car>  $cars
     */
    private function pairEntriesWithCars(Collection $entries, Collection $cars): void
    {
        foreach ($entries as $index => $entry) {
            $carId = $cars->get($index)?->id;

            if ($entry->car_id !== $carId) {
                $entry->update(['car_id' => $carId]);
            }
        }
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
            'starting_positions' => 'integer',
            'sort_order' => 'integer',
            'timing_status' => TimingStatus::class,
        ];
    }
}
