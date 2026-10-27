<?php

use App\Models\Competitor;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceType;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Races & Lineups')] class extends Component
{
    public string $search = '';

    public ?int $typeFilter = null;

    public string $newTypeName = '';

    public int $newTypeRaceCount = 1;

    public ?int $editingRaceId = null;

    /** @var array<string, string|null> */
    public array $raceForm = [];

    /**
     * Bumped after every drop so the sortable lists are rebuilt from server state.
     */
    public int $version = 0;

    #[Computed]
    public function raceTypes(): Collection
    {
        return RaceType::query()
            ->with('races.entries.competitor')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
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
        ], attributes: ['newTypeName' => 'race type name', 'newTypeRaceCount' => 'number of races']);

        $name = trim($this->newTypeName);
        $raceType = RaceType::query()->create([
            'name' => $name,
            'sort_order' => (int) RaceType::query()->max('sort_order') + 1,
        ]);

        for ($number = 1; $number <= $this->newTypeRaceCount; $number++) {
            $raceType->races()->create([
                'name' => $this->newTypeRaceCount === 1 ? $name : "{$name} {$number}",
                'sort_order' => $number,
            ]);
        }

        $this->reset('newTypeName', 'newTypeRaceCount');
        $this->dispatch('notify', message: "Added {$name}.");
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

    public function showDriver(int $competitorId): void
    {
        Setting::put(Setting::ActiveDriver, $this->activeDriverId === $competitorId ? null : (string) $competitorId);
        unset($this->activeDriverId);
    }
};
?>

<div class="flex items-start gap-4">
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
                        <input wire:model="raceForm.sponsor" class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-2 text-sm focus:border-amber-500 focus:outline-none">
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
                        <span class="w-10 shrink-0 rounded bg-slate-900 py-0.5 text-center text-xs font-black text-white">{{ $competitor->car_number ?: '—' }}</span>
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
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">Add type</button>
            </form>
            @error('newTypeName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @error('newTypeRaceCount') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

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
                    <button
                        type="button"
                        wire:click="deleteRaceType({{ $raceType->id }})"
                        wire:confirm="Delete {{ $raceType->name }} and all of its races?"
                        class="rounded-md px-2.5 py-1 text-xs font-bold text-red-600 hover:bg-red-50"
                    >Delete type</button>
                </div>

                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                    @forelse ($raceType->races as $race)
                        @php($isLive = $this->activeRaceId === $race->id)
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
                                    <span class="text-xs text-slate-400">{{ $race->entries->count() }} cars</span>
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
                                        <span class="w-9 shrink-0 rounded bg-slate-900 py-0.5 text-center text-xs font-black text-white">{{ $entry->competitor->car_number ?: '—' }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $entry->competitor->full_name }}</span>
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
