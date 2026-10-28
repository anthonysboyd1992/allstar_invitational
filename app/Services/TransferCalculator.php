<?php

namespace App\Services;

use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\RaceTypeTransferRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransferCalculator
{
    /**
     * Destination race this finisher would transfer into, based on current scores.
     */
    public function destinationLabel(RaceType $source, RaceEntry $entry): ?string
    {
        if ($entry->finish_position === null) {
            return null;
        }

        $source->loadMissing(['transferRules.destination.races', 'races.entries']);
        $races = $source->racesInProgramOrder();
        $race = $races->firstWhere('id', $entry->race_id);

        foreach ($source->transferRules as $rule) {
            $from = (int) $rule->position_from;
            $last = $race ? (int) $race->entries->max('finish_position') : (int) $entry->finish_position;
            $to = $rule->position_to ?? max($last, (int) $entry->finish_position);

            if ($entry->finish_position < $from || $entry->finish_position > max($from, $to)) {
                continue;
            }

            $destination = $rule->destination;

            if ($destination === null) {
                return null;
            }

            $destRaces = $destination->racesInProgramOrder()->values();

            if ($destRaces->isEmpty()) {
                return $destination->name;
            }

            if ($rule->distribute !== RaceTypeTransferRule::DistributeStagger) {
                return $destRaces->first()->name;
            }

            $index = $this->finishersForRule($races, $rule)->search(
                fn (array $finisher): bool => $finisher['competitor_id'] === $entry->competitor_id
                    && $finisher['source_race_id'] === $entry->race_id,
            );

            if ($index === false) {
                return $destination->name;
            }

            return $destRaces[$index % $destRaces->count()]->name;
        }

        return null;
    }

    /**
     * Apply this type's transfer rules to destination lineups.
     *
     * @return string|null error message when scoring is incomplete
     */
    public function calculate(RaceType $source): ?string
    {
        $source->load(['races.entries', 'transferRules.destination.races.entries']);
        $races = $source->racesInProgramOrder();

        foreach ($races as $race) {
            if ($race->entries->isEmpty()) {
                continue;
            }

            if ($race->entries->contains(fn (RaceEntry $entry): bool => $entry->finish_position === null)) {
                return 'Score every driver in '.$race->name.' before calculating.';
            }

            if ($race->entries->pluck('finish_position')->duplicates()->isNotEmpty()) {
                return 'Finish positions in '.$race->name.' must be unique.';
            }
        }

        if ($source->transferRules->isEmpty()) {
            return 'Add transfer rules on this race type first.';
        }

        $sourceRaceIds = $races->pluck('id')->all();

        DB::transaction(function () use ($source, $races, $sourceRaceIds): void {
            $destTypes = $source->transferRules
                ->map->destination
                ->filter()
                ->unique('id');

            foreach ($destTypes as $destType) {
                foreach ($destType->racesInProgramOrder() as $destRace) {
                    $destRace->removeTransfersFrom($sourceRaceIds);
                }
            }

            $source->unsetRelation('transferRules');
            $source->load(['transferRules.destination.races.entries']);

            foreach ($source->transferRules as $rule) {
                $this->applyRule($races, $rule);
            }
        });

        return null;
    }

    /**
     * @param  Collection<int, Race>  $sourceRaces
     */
    private function applyRule(Collection $sourceRaces, RaceTypeTransferRule $rule): void
    {
        $destination = $rule->destination;

        if ($destination === null) {
            return;
        }

        $destRaces = $destination->racesInProgramOrder()->values();

        if ($destRaces->isEmpty()) {
            return;
        }

        $finishers = $this->finishersForRule($sourceRaces, $rule);

        if ($rule->distribute === RaceTypeTransferRule::DistributeStagger) {
            foreach ($finishers as $index => $finisher) {
                $this->placeFinisher($destRaces[$index % $destRaces->count()], $finisher, $rule);
            }

            return;
        }

        $dest = $destRaces->first();

        foreach ($finishers as $finisher) {
            $this->placeFinisher($dest, $finisher, $rule);
        }
    }

    /**
     * @param  Collection<int, Race>  $sourceRaces
     * @return Collection<int, array{competitor_id: int, source_race_id: int}>
     */
    private function finishersForRule(Collection $sourceRaces, RaceTypeTransferRule $rule): Collection
    {
        $finishers = collect();

        foreach ($sourceRaces as $race) {
            $last = (int) $race->entries->max('finish_position');
            $from = (int) $rule->position_from;
            $to = $rule->position_to ?? $last;

            if ($from < 1 || $last < 1) {
                continue;
            }

            foreach (range($from, max($from, $to)) as $position) {
                $entry = $race->entries->firstWhere('finish_position', $position);

                if ($entry) {
                    $finishers->push([
                        'competitor_id' => $entry->competitor_id,
                        'source_race_id' => $race->id,
                    ]);
                }
            }
        }

        return $finishers->values();
    }

    /**
     * @param  array{competitor_id: int, source_race_id: int}  $finisher
     */
    private function placeFinisher(Race $dest, array $finisher, RaceTypeTransferRule $rule): void
    {
        $dest->unsetRelation('entries');
        $dest->load('entries');

        $slot = $dest->nextOpenSlotFrom($rule->destination_start_slot);

        if ($slot === null) {
            return;
        }

        $dest->placeTransfer($finisher['competitor_id'], $slot, $finisher['source_race_id']);
    }
}
