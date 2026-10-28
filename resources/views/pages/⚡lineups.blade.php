<?php

use App\Models\Car;
use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Races & Lineups')] class extends Component
{
    public string $search = '';

    public ?int $typeFilter = null;

    public string $newTypeName = '';

    public int $newTypeRaceCount = 1;

    public ?int $newTypeCarsPerRace = null;

    public ?int $newTypeCarsAllowed = null;

    public ?int $editingRaceId = null;

    /** @var array<string, string|null> */
    public array $raceForm = [];

    /**
     * Bumped after every drop so the sortable lists are rebuilt from server state.
     */
    public int $version = 0;

    public bool $printPickerOpen = false;

    /** @var list<string> */
    public array $printRaceIds = [];

    #[Computed]
    public function raceTypes(): Collection
    {
        return RaceType::query()
            ->with(['races.entries.competitor', 'races.entries.car', 'cars'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Car>
     */
    #[Computed]
    public function cars(): Collection
    {
        return Car::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Drivers not yet in the live race first, then the ones already in it.
     */
    #[Computed]
    public function roster(): Collection
    {
        $competitors = Competitor::query()
            ->withCount('entries')
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(fn ($inner) => $inner
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('car_number', 'like', $term));
            })
            ->get()
            ->sortBy('car_number', SORT_NATURAL | SORT_FLAG_CASE);

        $liveLineupIds = $this->liveRace?->entries->pluck('competitor_id')->all() ?? [];
        [$inLiveRace, $available] = $competitors->partition(fn (Competitor $competitor) => in_array($competitor->id, $liveLineupIds, true));

        return $available->concat($inLiveRace)->values();
    }

    #[Computed]
    public function activeRaceId(): ?int
    {
        return ($id = Setting::get(Setting::ActiveRace)) ? (int) $id : null;
    }

    #[Computed]
    public function liveRace(): ?Race
    {
        return $this->raceTypes->flatMap->races->firstWhere('id', $this->activeRaceId);
    }

    #[Computed]
    public function activeDriverId(): ?int
    {
        return ($id = Setting::get(Setting::ActiveDriver)) ? (int) $id : null;
    }

    public function addRaceType(): void
    {
        $this->validate([
            'newTypeName' => ['required', 'string', 'max:100'],
            'newTypeRaceCount' => ['required', 'integer', 'min:0', 'max:50'],
            'newTypeCarsPerRace' => ['nullable', 'integer', 'min:1', 'max:50'],
            'newTypeCarsAllowed' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], attributes: [
            'newTypeName' => 'race type name',
            'newTypeRaceCount' => 'number of races',
            'newTypeCarsPerRace' => 'group size',
            'newTypeCarsAllowed' => 'cars allowed',
        ]);

        $name = trim($this->newTypeName);
        $raceType = RaceType::query()->create([
            'name' => $name,
            'sort_order' => (int) RaceType::query()->max('sort_order') + 1,
            'cars_per_race' => $this->newTypeCarsPerRace,
            'cars_allowed' => $this->newTypeCarsAllowed,
        ]);

        for ($number = 1; $number <= $this->newTypeRaceCount; $number++) {
            $raceType->races()->create([
                'name' => $this->newTypeRaceCount === 1 ? $name : "{$name} {$number}",
                'sort_order' => $number,
            ]);
        }

        $this->reset('newTypeName', 'newTypeRaceCount', 'newTypeCarsPerRace', 'newTypeCarsAllowed');
        $this->dispatch('notify', message: "Added {$name}.");
    }

    public function toggleAlternateGroups(int $raceTypeId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);

        $raceType->update([
            'cars_per_race' => $raceType->cars_per_race ? null : $raceType->suggestedGroupSize(),
        ]);
        $raceType->assignCarsByWeight();
        $this->version++;
    }

    public function updateCarsPerRace(int $raceTypeId, mixed $value): void
    {
        $value = is_string($value) ? trim($value) : $value;
        $value = $value === '' ? null : $value;

        $validator = Validator::make(
            ['cars_per_race' => $value],
            ['cars_per_race' => ['nullable', 'integer', 'min:1', 'max:50']],
            [],
            ['cars_per_race' => 'cars per race'],
        );

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');

            return;
        }

        $raceType = RaceType::query()->findOrFail($raceTypeId);
        $raceType->update(['cars_per_race' => $validator->validated()['cars_per_race']]);
        $raceType->assignCarsByWeight();
        $this->version++;
    }

    public function updateCarsAllowed(int $raceTypeId, mixed $value): void
    {
        $value = is_string($value) ? trim($value) : $value;
        $value = $value === '' ? null : $value;

        $validator = Validator::make(
            ['cars_allowed' => $value],
            ['cars_allowed' => ['nullable', 'integer', 'min:1', 'max:50']],
            [],
            ['cars_allowed' => 'cars allowed'],
        );

        if ($validator->fails()) {
            $this->dispatch('notify', message: $validator->errors()->first(), type: 'error');

            return;
        }

        RaceType::query()->findOrFail($raceTypeId)->update(['cars_allowed' => $validator->validated()['cars_allowed']]);
        $this->version++;
    }

    public function togglePoolCar(int $raceTypeId, int $carId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);
        $car = Car::query()->findOrFail($carId);

        if ($raceType->cars()->where('cars.id', $car->id)->exists()) {
            $raceType->cars()->detach($car->id);
            $raceType->compactPoolOrder();
        } else {
            if ($raceType->poolIsFull()) {
                $this->dispatch('notify', message: $raceType->name.' already has its '.$raceType->cars_allowed.' cars.', type: 'error');

                return;
            }

            $raceType->cars()->attach($car->id, ['sort_order' => $raceType->cars()->count()]);
        }

        $raceType->assignCarsByWeight();
        $this->version++;
    }

    public function sortPoolByTime(int $raceTypeId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);
        $raceType->sortPoolByTime();
        $raceType->assignCarsByWeight();
        $this->version++;
    }

    public function dealPool(int $raceTypeId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);

        if (! $raceType->dealIntoGroups()) {
            $this->dispatch('notify', message: 'Turn Alternate on so the pool can split, then deal.', type: 'error');

            return;
        }

        $raceType->assignCarsByWeight();
        $this->version++;
        $this->dispatch('notify', message: 'Dealt cars into groups (1st to group 1, 2nd to group 2, and so on).');
    }

    /**
     * Drag-and-drop handler for a type's car pool. Position 0 is first in the rotation.
     */
    public function sortPool(int|string $carId, int $position, int|string|null $raceTypeId = null): void
    {
        $raceType = RaceType::query()->findOrFail((int) $raceTypeId);
        $pool = $raceType->cars()->get();
        $moving = $pool->firstWhere('id', (int) $carId);

        if (! $moving) {
            return;
        }

        $pool = $pool->reject(fn (Car $car) => $car->id === $moving->id)->values();
        $pool->splice(max(0, min($position, $pool->count())), 0, [$moving]);
        $pool->each(fn (Car $car, int $index) => $raceType->cars()->updateExistingPivot($car->id, ['sort_order' => $index]));
        $raceType->assignCarsByWeight();
        $this->version++;
    }

    public function assignCarsByWeight(int $raceTypeId): void
    {
        RaceType::query()->findOrFail($raceTypeId)->assignCarsByWeight();
        $this->dispatch('notify', message: 'Assigned cars by weight (heaviest to fastest).');
    }

    public function renameRaceType(int $raceTypeId, string $name): void
    {
        if (trim($name) === '') {
            return;
        }

        RaceType::query()->whereKey($raceTypeId)->update(['name' => trim($name)]);
    }

    public function deleteRaceType(int $raceTypeId): void
    {
        RaceType::query()->whereKey($raceTypeId)->delete();

        if ($this->typeFilter === $raceTypeId) {
            $this->typeFilter = null;
        }
    }

    public function addRace(int $raceTypeId): void
    {
        $raceType = RaceType::query()->findOrFail($raceTypeId);
        $number = $raceType->races()->count() + 1;

        $raceType->races()->create([
            'name' => "{$raceType->name} {$number}",
            'sort_order' => (int) $raceType->races()->max('sort_order') + 1,
        ]);
    }

    public function openRaceEditor(int $raceId): void
    {
        $race = Race::query()->findOrFail($raceId);

        $this->resetValidation();
        $this->editingRaceId = $race->id;
        $this->raceForm = [
            'name' => $race->name,
            'race_type_id' => (string) $race->race_type_id,
            'laps' => (string) $race->laps,
            'transfers' => (string) $race->transfers,
            'sponsor' => (string) $race->sponsor,
        ];
    }

    public function closeRaceEditor(): void
    {
        $this->editingRaceId = null;
        $this->raceForm = [];
        $this->resetValidation();
    }

    public function saveRace(): void
    {
        $race = Race::query()->findOrFail($this->editingRaceId);

        $this->raceForm = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $this->raceForm);

        $data = $this->validate([
            'raceForm.name' => ['required', 'string', 'max:100'],
            'raceForm.race_type_id' => ['required', 'integer', 'exists:race_types,id'],
            'raceForm.laps' => ['nullable', 'integer', 'min:0', 'max:999'],
            'raceForm.transfers' => ['nullable', 'string', 'max:150'],
            'raceForm.sponsor' => ['nullable', 'string', 'max:150'],
        ], attributes: [
            'raceForm.name' => 'race name',
            'raceForm.race_type_id' => 'race type',
            'raceForm.laps' => 'laps',
            'raceForm.transfers' => 'transfer text',
            'raceForm.sponsor' => 'sponsor',
        ])['raceForm'];

        $race->update(array_map(fn ($value) => $value === '' ? null : $value, $data));
        $race->unsetRelation('raceType');
        $race->assignCarsByWeight();

        $this->closeRaceEditor();
        $this->dispatch('notify', message: "Saved {$race->name}.");
    }

    public function deleteRace(int $raceId): void
    {
        Race::query()->whereKey($raceId)->delete();

        if ($this->activeRaceId === $raceId) {
            Setting::put(Setting::ActiveRace, null);
        }
    }

    /**
     * Handle a drop from wire:sort. Roster items are keyed "c{competitorId}", lineup items "e{entryId}".
     */
    public function placeDriver(string $item, int $position, int|string|null $raceId = null): void
    {
        $target = Race::query()->findOrFail((int) $raceId);
        $source = null;

        if (str_starts_with($item, 'e')) {
            $entry = RaceEntry::query()->findOrFail((int) substr($item, 1));
            $competitorId = $entry->competitor_id;
            $source = $entry->race_id === $target->id ? null : $entry->race;
        } else {
            $competitorId = Competitor::query()->findOrFail((int) substr($item, 1))->id;
        }

        $lineup = $target->lineupIds();
        $alreadyInTarget = in_array($competitorId, $lineup, true);
        $isReorder = str_starts_with($item, 'e') && $source === null;

        if ($alreadyInTarget && ! $isReorder) {
            $this->version++;
            $this->dispatch('notify', message: 'That driver is already in '.$target->name.'.', type: 'error');

            return;
        }

        $lineup = array_values(array_diff($lineup, [$competitorId]));
        array_splice($lineup, max(0, min($position, count($lineup))), 0, [$competitorId]);
        $target->syncLineup($lineup);

        $source?->syncLineup(array_values(array_diff($source->lineupIds(), [$competitorId])));

        $this->version++;
    }

    public function removeFromRace(int $raceId, int $competitorId): void
    {
        $race = Race::query()->findOrFail($raceId);
        $race->syncLineup(array_values(array_diff($race->lineupIds(), [$competitorId])));
        $this->version++;
    }

    public function clearRace(int $raceId): void
    {
        Race::query()->findOrFail($raceId)->syncLineup([]);
        $this->version++;
    }

    public function goLive(int $raceId): void
    {
        if ($this->activeRaceId === $raceId) {
            return;
        }

        Setting::put(Setting::ActiveRace, (string) Race::query()->findOrFail($raceId)->id);
        unset($this->activeRaceId);
    }

    public function addToLiveRace(int $competitorId): void
    {
        $race = $this->activeRaceId ? Race::query()->find($this->activeRaceId) : null;

        if (! $race) {
            $this->dispatch('notify', message: 'Click a race first to make it live.', type: 'error');

            return;
        }

        $lineup = $race->lineupIds();

        if (in_array($competitorId, $lineup, true)) {
            $this->dispatch('notify', message: 'That driver is already in '.$race->name.'.', type: 'error');

            return;
        }

        $race->syncLineup([...$lineup, Competitor::query()->findOrFail($competitorId)->id]);
        $this->version++;
    }

    public function moveRaceType(int $raceTypeId, int $direction): void
    {
        $ids = RaceType::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $index = array_search($raceTypeId, $ids, true);
        $swapWith = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($swapWith === null || ! isset($ids[$swapWith])) {
            return;
        }

        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];

        foreach ($ids as $position => $id) {
            RaceType::query()->whereKey($id)->update(['sort_order' => $position + 1]);
        }
    }

    public function openPrintPicker(?int $raceTypeId = null): void
    {
        $filter = $raceTypeId ?? $this->typeFilter;
        $this->printPickerOpen = true;
        $this->printRaceIds = $this->raceTypes
            ->when($filter, fn (Collection $types) => $types->where('id', $filter))
            ->flatMap->races
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function closePrintPicker(): void
    {
        $this->printPickerOpen = false;
    }

    public function selectAllPrintRaces(): void
    {
        $this->printRaceIds = $this->raceTypes
            ->flatMap->races
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function clearPrintRaces(): void
    {
        $this->printRaceIds = [];
    }

    public function togglePrintType(int $raceTypeId): void
    {
        $ids = $this->raceTypes->firstWhere('id', $raceTypeId)?->races
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all() ?? [];
        $selected = collect($this->printRaceIds)->map(fn ($id): string => (string) $id);

        $this->printRaceIds = collect($ids)->diff($selected)->isEmpty() && $ids !== []
            ? $selected->diff($ids)->values()->all()
            : $selected->merge($ids)->unique()->values()->all();
    }

    public function updatedPrintRaceIds(mixed $value): void
    {
        $this->printRaceIds = collect($value)
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Letter-size sheet for the checked races. Empty selection must not print every race.
     */
    public function printSheetUrl(): ?string
    {
        $ids = collect($this->printRaceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? null : route('lineups.print', ['races' => $ids]);
    }

    public function showDriver(int $competitorId): void
    {
        Setting::put(Setting::ActiveDriver, $this->activeDriverId === $competitorId ? null : (string) $competitorId);
        unset($this->activeDriverId);
    }
};
?>

<div class="flex items-start gap-4">
    @if ($printPickerOpen)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closePrintPicker()"
            wire:click.self="closePrintPicker"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">Print lineups</h2>
                    <button type="button" wire:click="closePrintPicker" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>
                <p class="mb-3 text-sm text-slate-500">Choose the races to put on the landscape letter sheet.</p>
                <div class="mb-3 flex gap-2 text-xs font-bold">
                    <button type="button" wire:click="selectAllPrintRaces" class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">Select all</button>
                    <button type="button" wire:click="clearPrintRaces" class="rounded px-2 py-1 ring-1 ring-slate-300 hover:bg-slate-50">Clear</button>
                </div>
                <div class="max-h-80 space-y-3 overflow-y-auto">
                    @foreach ($this->raceTypes as $raceType)
                        <div wire:key="print-type-{{ $raceType->id }}">
                            <label class="flex items-center gap-2 text-sm font-black uppercase">
                                <input
                                    type="checkbox"
                                    wire:click="togglePrintType({{ $raceType->id }})"
                                    @checked($raceType->races->isNotEmpty() && $raceType->races->every(fn ($race) => in_array((string) $race->id, $printRaceIds, true)))
                                    @disabled($raceType->races->isEmpty())
                                >
                                {{ $raceType->name }}
                            </label>
                            <div class="mt-1 ml-6 space-y-1">
                                @forelse ($raceType->racesInProgramOrder() as $race)
                                    <label wire:key="print-race-{{ $race->id }}" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model.live="printRaceIds" value="{{ $race->id }}">
                                        {{ $race->name }}
                                    </label>
                                @empty
                                    <p class="text-xs text-slate-400">No races in this type.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closePrintPicker" class="rounded-md px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <a
                        href="{{ $this->printSheetUrl() ?? '#' }}"
                        target="_blank"
                        @class(['rounded-md px-4 py-2 text-sm font-semibold', 'bg-slate-900 text-white hover:bg-slate-700' => $this->printSheetUrl(), 'pointer-events-none bg-slate-200 text-slate-400' => ! $this->printSheetUrl()])
                    >Print selected</a>
                </div>
            </div>
        </div>
    @endif

    @if ($editingRaceId)
        <div
            class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20"
            x-on:keydown.escape.window="$wire.closeRaceEditor()"
            wire:click.self="closeRaceEditor"
        >
            <form
                wire:submit="saveRace"
                x-init="$nextTick(() => $el.querySelector('input')?.select())"
                class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black uppercase">Edit race</h2>
                    <button type="button" wire:click="closeRaceEditor" class="rounded px-2 text-2xl leading-none text-slate-400 hover:text-slate-900">&times;</button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Race name</span>
                        <input wire:model="raceForm.name" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
                        @error('raceForm.name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Race type</span>
                        <select wire:model="raceForm.race_type_id" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
                            @foreach ($this->raceTypes as $raceType)
                                <option value="{{ $raceType->id }}" wire:key="edit-type-{{ $raceType->id }}">{{ $raceType->name }}</option>
                            @endforeach
                        </select>
                        @error('raceForm.race_type_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Laps</span>
                        <input type="number" min="0" wire:model="raceForm.laps" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
                        @error('raceForm.laps') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-500">Transfer text</span>
                        <input wire:model="raceForm.transfers" placeholder="e.g. Top 2 transfer" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
                        @error('raceForm.transfers') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="col-span-2 block">
                        <span class="text-xs font-semibold text-slate-500">Sponsor</span>
                        <input wire:model="raceForm.sponsor" placeholder="Put sponsor here" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
                        @error('raceForm.sponsor') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <p class="mt-3 text-xs text-slate-400">Laps and transfer text feed the vMix lineup ("Trans_Dist.Text") and the timing .dat file.</p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeRaceEditor" class="rounded-md px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Save race</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Roster --}}
    <aside class="sticky top-18 flex max-h-[calc(100vh-5.5rem)] w-72 shrink-0 flex-col rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-200 p-3">
            <h2 class="text-sm font-bold tracking-wide text-slate-500 uppercase">Drivers</h2>
            <p class="mb-2 text-xs text-slate-400">
                @if ($this->liveRace)
                    Click to add to <span class="font-bold text-red-600">{{ $this->liveRace->name }}</span>, or drag onto any race.
                @else
                    Click a race to make it live, then click drivers to add them. You can also drag.
                @endif
            </p>
            <input
                type="search"
                wire:model.live.debounce.250ms="search"
                placeholder="Search name or car #"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            >
        </div>

        <div class="flex-1 overflow-y-auto p-2">
            <div
                wire:key="roster-{{ $version }}"
                wire:sort="placeDriver"
                x-sort:config="{ group: { name: 'drivers', pull: 'clone', put: false }, sort: false }"
                class="space-y-1"
            >
                @php($liveLineupIds = $this->liveRace?->entries->pluck('competitor_id')->all() ?? [])
                @forelse ($this->roster as $competitor)
                    @php($inLiveRace = in_array($competitor->id, $liveLineupIds, true))
                    <div
                        wire:key="roster-{{ $version }}-{{ $competitor->id }}"
                        wire:sort:item="c{{ $competitor->id }}"
                        wire:click="addToLiveRace({{ $competitor->id }})"
                        @class([
                            'flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1.5 text-sm hover:border-amber-400',
                            'border-red-300 bg-red-50' => $this->activeDriverId === $competitor->id,
                            'border-slate-200 bg-white' => $this->activeDriverId !== $competitor->id,
                            'opacity-50' => $inLiveRace,
                        ])
                    >
                        <span class="w-10 shrink-0 rounded bg-slate-900 py-0.5 text-center text-xs font-black text-white">{{ filled($competitor->car_number) ? $competitor->car_number : '—' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">{{ $competitor->full_name }}</span>
                            <span class="block text-xs text-slate-400">
                                Pill {{ $competitor->pill_number ?? '—' }} · {{ $competitor->weight ? $competitor->weight.' lb' : '— lb' }} · {{ $competitor->entries_count }} {{ Str::plural('race', $competitor->entries_count) }}
                            </span>
                        </span>
                        <button
                            type="button"
                            wire:sort:ignore
                            wire:click.stop="showDriver({{ $competitor->id }})"
                            title="Send to the driver feed"
                            @class([
                                'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold',
                                'bg-red-600 text-white' => $this->activeDriverId === $competitor->id,
                                'bg-slate-100 text-slate-500 hover:bg-slate-200' => $this->activeDriverId !== $competitor->id,
                            ])
                        >{{ $this->activeDriverId === $competitor->id ? 'ON AIR' : 'Show' }}</button>
                    </div>
                @empty
                    <p class="p-4 text-center text-sm text-slate-400">
                        No drivers. <a href="{{ route('competitors') }}" wire:navigate class="text-amber-600 underline">Add competitors</a>
                    </p>
                @endforelse
            </div>
        </div>
    </aside>

    {{-- Races --}}
    <section class="min-w-0 flex-1 space-y-4">
        <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
            <form wire:submit="addRaceType" class="flex items-end gap-2">
                <label class="block">
                    <span class="text-xs font-semibold text-slate-500">New race type</span>
                    <input wire:model="newTypeName" placeholder="Heat, Hot Laps, A-Main…" class="mt-0.5 block w-48 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-slate-500">Races</span>
                    <input type="number" min="0" max="50" wire:model="newTypeRaceCount" class="mt-0.5 block w-20 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                </label>
                <label class="block" title="Max cars this type can use, e.g. 10 for heats or 12 for the feature.">
                    <span class="text-xs font-semibold text-slate-500">Cars allowed</span>
                    <input type="number" min="1" max="50" wire:model="newTypeCarsAllowed" placeholder="any" class="mt-0.5 block w-20 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                </label>
                <label class="block" title="Leave blank so every race uses the whole pool. Set a smaller number to alternate groups.">
                    <span class="text-xs font-semibold text-slate-500">Group size</span>
                    <input type="number" min="1" max="50" wire:model="newTypeCarsPerRace" placeholder="all" class="mt-0.5 block w-20 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-amber-500 focus:outline-none">
                </label>
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">Add type</button>
            </form>
            @if ($this->raceTypes->isNotEmpty())
                <button type="button" wire:click="openPrintPicker" class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold ring-1 ring-slate-300 hover:bg-slate-50">Print</button>
            @endif
            @error('newTypeName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @error('newTypeRaceCount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @error('newTypeCarsAllowed') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @error('newTypeCarsPerRace') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

            @if ($this->raceTypes->isNotEmpty())
                <div class="ml-auto flex flex-wrap gap-1 text-sm font-semibold">
                    <button type="button" wire:click="$set('typeFilter', null)" @class(['rounded-md px-3 py-1.5', 'bg-amber-400' => $typeFilter === null, 'bg-slate-100 hover:bg-slate-200' => $typeFilter !== null])>All</button>
                    @foreach ($this->raceTypes as $raceType)
                        <button type="button" wire:key="filter-{{ $raceType->id }}" wire:click="$set('typeFilter', {{ $raceType->id }})" @class(['rounded-md px-3 py-1.5', 'bg-amber-400' => $typeFilter === $raceType->id, 'bg-slate-100 hover:bg-slate-200' => $typeFilter !== $raceType->id])>{{ $raceType->name }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        @forelse ($this->raceTypes->when($typeFilter, fn ($types) => $types->where('id', $typeFilter)) as $raceType)
            <div wire:key="type-{{ $raceType->id }}" class="space-y-2">
                <div class="flex items-center gap-2">
                    <div class="flex flex-col">
                        <button type="button" wire:click="moveRaceType({{ $raceType->id }}, -1)" @disabled($loop->first) title="Move up" class="px-1 text-xs leading-none text-slate-400 hover:text-slate-900 disabled:opacity-25">&#9650;</button>
                        <button type="button" wire:click="moveRaceType({{ $raceType->id }}, 1)" @disabled($loop->last) title="Move down" class="px-1 text-xs leading-none text-slate-400 hover:text-slate-900 disabled:opacity-25">&#9660;</button>
                    </div>
                    <input
                        value="{{ $raceType->name }}"
                        wire:change="renameRaceType({{ $raceType->id }}, $event.target.value)"
                        class="rounded-md border border-transparent bg-transparent px-1 text-lg font-black uppercase hover:border-slate-300 focus:border-amber-500 focus:bg-white focus:outline-none"
                    >
                    <button type="button" wire:click="addRace({{ $raceType->id }})" class="rounded-md bg-white px-2.5 py-1 text-xs font-bold ring-1 ring-slate-300 hover:bg-slate-50">+ Race</button>
                    <button type="button" wire:click="openPrintPicker({{ $raceType->id }})" class="rounded-md bg-white px-2.5 py-1 text-xs font-bold ring-1 ring-slate-300 hover:bg-slate-50">Print</button>
                    <button
                        type="button"
                        wire:click="deleteRaceType({{ $raceType->id }})"
                        wire:confirm="Delete {{ $raceType->name }} and all of its races?"
                        class="rounded-md px-2.5 py-1 text-xs font-bold text-red-600 hover:bg-red-50"
                    >Delete type</button>
                    <label class="ml-auto flex items-center gap-1 text-xs font-semibold text-slate-500">
                        Allowed
                        <input
                            type="number"
                            min="1"
                            max="50"
                            value="{{ $raceType->cars_allowed }}"
                            wire:change="updateCarsAllowed({{ $raceType->id }}, $event.target.value)"
                            placeholder="any"
                            class="w-14 rounded-md border border-slate-300 px-1.5 py-1 text-sm font-bold text-slate-900 focus:border-amber-500 focus:outline-none"
                        >
                    </label>
                    <button
                        type="button"
                        wire:click="toggleAlternateGroups({{ $raceType->id }})"
                        title="Heats: split the pool so consecutive races use different cars. B-Mains and the feature: leave off so every race uses the same cars."
                        @class([
                            'rounded-md px-2.5 py-1 text-xs font-bold',
                            'bg-amber-400 text-slate-900' => $raceType->cars_per_race,
                            'bg-slate-100 text-slate-600 ring-1 ring-slate-300 hover:bg-slate-200' => ! $raceType->cars_per_race,
                        ])
                    >Alternate</button>
                    <label @class(['flex items-center gap-1 text-xs font-semibold', 'text-slate-500' => $raceType->cars_per_race, 'text-slate-300' => ! $raceType->cars_per_race])>
                        groups of
                        <input
                            type="number"
                            min="1"
                            max="50"
                            value="{{ $raceType->cars_per_race }}"
                            wire:change="updateCarsPerRace({{ $raceType->id }}, $event.target.value)"
                            @disabled(! $raceType->cars_per_race)
                            placeholder="all"
                            class="w-14 rounded-md border border-slate-300 px-1.5 py-1 text-sm font-bold text-slate-900 focus:border-amber-500 focus:outline-none disabled:bg-slate-50 disabled:text-slate-400"
                        >
                    </label>
                    <button
                        type="button"
                        wire:click="assignCarsByWeight({{ $raceType->id }})"
                        @disabled($raceType->cars->isEmpty())
                        class="rounded-md bg-amber-400 px-2.5 py-1 text-xs font-black text-slate-900 hover:bg-amber-300 disabled:opacity-40"
                    >Assign by weight</button>
                </div>

                @php($poolFull = $raceType->poolIsFull())
                <div class="overflow-hidden rounded-lg ring-1 ring-slate-200">
                    <div class="flex flex-wrap items-center gap-1 bg-slate-900 px-2 py-1">
                        <span class="text-[10px] font-black tracking-wide text-amber-400 uppercase">In {{ $raceType->name }}</span>
                        <span class="rounded bg-white/10 px-1 font-mono text-[10px] font-bold text-white">
                            {{ $raceType->cars->count() }}@if ($raceType->cars_allowed)/{{ $raceType->cars_allowed }}@endif
                        </span>
                        @forelse ($raceType->carGroups() as $groupIndex => $group)
                            @if ($raceType->rotatesPool())
                                <span wire:key="pool-label-{{ $raceType->id }}-{{ $groupIndex }}" @class(['ml-1 text-[10px] font-black uppercase', 'text-amber-400' => $groupIndex % 2 === 0, 'text-white' => $groupIndex % 2 === 1])>G{{ $groupIndex + 1 }}</span>
                            @endif
                            @foreach ($group as $car)
                                <span
                                    wire:key="pool-{{ $raceType->id }}-{{ $car->id }}"
                                    @class([
                                        'inline-flex items-center rounded font-mono text-xs font-black',
                                        'bg-amber-400 text-slate-900' => $groupIndex % 2 === 0,
                                        'bg-white text-slate-900' => $groupIndex % 2 === 1,
                                    ])
                                >
                                    <span class="pl-1.5">{{ $car->number }}</span>
                                    <button
                                        type="button"
                                        wire:click="togglePoolCar({{ $raceType->id }}, {{ $car->id }})"
                                        title="Remove {{ $car->number }} from {{ $raceType->name }}"
                                        class="px-1 leading-none hover:bg-black/10"
                                    >&times;</button>
                                </span>
                            @endforeach
                        @empty
                            <p class="text-[11px] text-slate-400">Click a car below to add it to {{ $raceType->name }}.</p>
                        @endforelse
                        <button
                            type="button"
                            wire:click="dealPool({{ $raceType->id }})"
                            @disabled($raceType->cars->count() < 2 || ! $raceType->rotatesPool())
                            title="1st car to group 1, 2nd to group 2, and so on, so each race gets a mix of fast and slow."
                            class="ml-auto rounded px-1.5 py-0.5 text-[10px] font-bold text-slate-300 hover:bg-white/10 disabled:opacity-30"
                        >Deal groups</button>
                        <button
                            type="button"
                            wire:click="sortPoolByTime({{ $raceType->id }})"
                            @disabled($raceType->cars->isEmpty())
                            class="rounded px-1.5 py-0.5 text-[10px] font-bold text-slate-300 hover:bg-white/10 disabled:opacity-30"
                        >Fastest first</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-1 border-t border-dashed border-slate-300 bg-slate-50 px-2 py-1">
                        <span class="text-[10px] font-black tracking-wide text-slate-400 uppercase">Not in {{ $raceType->name }}</span>
                        @if ($poolFull)
                            <span class="text-[10px] font-semibold text-amber-700">Full</span>
                        @endif
                        @forelse ($this->cars->reject(fn (Car $car) => $raceType->cars->contains('id', $car->id)) as $car)
                            <button
                                type="button"
                                wire:key="add-pool-{{ $raceType->id }}-{{ $car->id }}"
                                wire:click="togglePoolCar({{ $raceType->id }}, {{ $car->id }})"
                                @disabled($poolFull)
                                title="{{ $poolFull ? $raceType->name.' already has '.$raceType->cars_allowed.' cars' : 'Add '.$car->number.' to '.$raceType->name }}"
                                class="rounded border border-dashed border-slate-300 bg-white px-1.5 font-mono text-[11px] font-bold leading-5 text-slate-400 hover:border-amber-400 hover:text-slate-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-slate-300 disabled:hover:text-slate-400"
                            >{{ $car->number }}</button>
                        @empty
                            @if ($this->cars->isEmpty())
                                <p class="text-[11px] text-slate-400">
                                    No cars yet. @can('access-cars')<a href="{{ route('cars') }}" wire:navigate class="text-amber-600 underline">Add cars</a>@else Add them on the Cars page.@endcan
                                </p>
                            @else
                                <p class="text-[11px] text-slate-400">Every car is in this type.</p>
                            @endif
                        @endforelse
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                    @forelse ($raceType->racesInProgramOrder() as $race)
                        @php($isLive = $this->activeRaceId === $race->id)
                        @php($raceCars = $race->poolCars())
                        @php($groupNumber = $race->poolGroupNumber())
                        <div
                            wire:key="race-{{ $race->id }}"
                            wire:click="goLive({{ $race->id }})"
                            title="{{ $isLive ? 'Live on the vMix lineup feed' : 'Click to make live' }}"
                            @class([
                                'flex flex-col rounded-xl bg-white shadow-sm',
                                'ring-2 ring-red-500' => $isLive,
                                'cursor-pointer ring-1 ring-slate-200 hover:ring-slate-400' => ! $isLive,
                            ])
                        >
                            <div class="border-b border-slate-100 px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate font-bold">{{ $race->name }}</span>
                                    <span class="text-xs text-slate-400">{{ $race->entries->count() }} drivers</span>
                                    @if ($isLive)
                                        <span class="rounded bg-red-600 px-2 py-0.5 text-xs font-bold text-white">LIVE</span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click.stop="openRaceEditor({{ $race->id }})"
                                        class="rounded px-2 py-0.5 text-xs font-bold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-100"
                                    >Edit</button>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    {{ collect([$race->laps ? $race->laps.' Laps' : 'No laps set', $race->transfers, $race->sponsor ? 'Sponsor: '.$race->sponsor : null])->filter()->implode(' · ') }}
                                </p>
                                @if ($raceCars->isNotEmpty())
                                    <p @class(['mt-0.5 truncate text-[11px] font-semibold', 'text-amber-700' => $groupNumber % 2 === 1, 'text-slate-600' => $groupNumber % 2 === 0]) title="Cars this race draws from the pool">
                                        @if ($raceType->rotatesPool())
                                            Group {{ $groupNumber }} ·
                                        @endif
                                        {{ $raceCars->pluck('number')->implode(', ') }}
                                    </p>
                                @endif
                            </div>

                            <div
                                wire:key="race-list-{{ $race->id }}-{{ $version }}"
                                wire:sort.ghost="placeDriver"
                                wire:sort:group="drivers"
                                wire:sort:group-id="{{ $race->id }}"
                                @class([
                                    'grid min-h-16 flex-1 grid-cols-2 content-start gap-1 p-2',
                                    'bg-[repeating-linear-gradient(45deg,transparent,transparent_8px,var(--color-slate-50)_8px,var(--color-slate-50)_16px)]' => $race->entries->isEmpty(),
                                ])
                            >
                                @foreach ($race->entries as $entry)
                                    <div
                                        wire:key="entry-{{ $entry->id }}"
                                        wire:sort:item="e{{ $entry->id }}"
                                        title="Row {{ (int) ceil($entry->start_position / 2) }} {{ $entry->start_position % 2 ? 'inside' : 'outside' }}"
                                        class="flex min-w-0 cursor-grab items-center gap-1.5 rounded-md border border-slate-200 bg-white px-1.5 py-1 text-sm hover:border-amber-400"
                                    >
                                        <span class="w-4 shrink-0 text-right text-xs font-bold text-slate-400">{{ $entry->start_position }}</span>
                                        <span class="w-9 shrink-0 rounded bg-slate-900 py-0.5 text-center text-xs font-black text-white">{{ filled($entry->competitor->car_number) ? $entry->competitor->car_number : '—' }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $entry->competitor->full_name }}</span>
                                        @if ($entry->car)
                                            <span title="Assigned invitational car" class="shrink-0 rounded bg-amber-400 px-1 py-0.5 font-mono text-[10px] font-black text-slate-900">{{ $entry->car->number }}</span>
                                        @endif
                                        <button
                                            type="button"
                                            wire:sort:ignore
                                            wire:click.stop="removeFromRace({{ $race->id }}, {{ $entry->competitor_id }})"
                                            title="Remove from {{ $race->name }}"
                                            class="rounded px-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                        >&times;</button>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-between border-t border-slate-100 px-2 py-1 text-xs">
                                <button
                                    type="button"
                                    wire:click.stop="clearRace({{ $race->id }})"
                                    wire:confirm="Remove every driver from {{ $race->name }}?"
                                    class="font-semibold text-slate-400 hover:text-slate-700"
                                >Clear lineup</button>
                                <button
                                    type="button"
                                    wire:click.stop="deleteRace({{ $race->id }})"
                                    wire:confirm="Delete {{ $race->name }}?"
                                    class="font-semibold text-red-500 hover:text-red-700"
                                >Delete race</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">No races yet — click “+ Race”.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="rounded-xl border-2 border-dashed border-slate-300 p-10 text-center text-slate-400">
                Add a race type above — e.g. “Heat” with 4 races, or “Hot Laps” with 1.
            </div>
        @endforelse
    </section>
</div>
